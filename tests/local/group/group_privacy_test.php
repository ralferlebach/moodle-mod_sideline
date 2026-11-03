<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_sideline\local\group;

use mod_sideline\local\ai\ai_gateway;
use mod_sideline\local\consent\consent_manager;
use mod_sideline\local\instance_config;
use mod_sideline\local\service\commentary_service;
use mod_sideline\tests\fake_text_generator;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/sideline/tests/fixtures/fake_text_generator.php');
require_once($CFG->dirroot . '/mod/sideline/lib.php');

#[\PHPUnit\Framework\Attributes\CoversClass(group_service::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(sharing_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(instance_config::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(consent_manager::class)]
/**
 * Group mode: event types (issue #6), set-based queries (issue #7), renewed decisions when the circle
 * grows (issue #9) and protection of aggregates against differencing (issue #12).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_sideline\local\group\group_service
 * @covers \mod_sideline\local\group\sharing_manager
 * @covers \mod_sideline\local\instance_config
 * @covers \mod_sideline\local\consent\consent_manager
 */
final class group_privacy_test extends \advanced_testcase {
    /** @var \stdClass Course. */
    private \stdClass $course;
    /** @var \stdClass Recipient. */
    private \stdClass $recipient;
    /** @var \stdClass Own group. */
    private \stdClass $own;
    /** @var \stdClass Activity. */
    private \stdClass $page;
    /** @var \stdClass Activity with a pass grade. */
    private \stdClass $quiz;
    /** @var \stdClass[] One member per group who does not share. */
    private array $bystanders = [];

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('enablecompletion', 1);
        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course(['enablecompletion' => 1]);
        $this->page = $gen->create_module('page', ['course' => $this->course->id, 'name' => 'Unit A',
            'completion' => COMPLETION_TRACKING_MANUAL]);
        $this->quiz = $gen->create_module('page', ['course' => $this->course->id, 'name' => 'Check B',
            'completion' => COMPLETION_TRACKING_MANUAL]);
        $this->own = $gen->create_group(['courseid' => $this->course->id, 'name' => 'Red team']);
        $this->recipient = $gen->create_and_enrol($this->course, 'student', ['firstname' => 'Rita']);
        $gen->create_group_member(['groupid' => $this->own->id, 'userid' => $this->recipient->id]);
    }

    /**
     * Members of the own group who share.
     *
     * @param \stdClass $sideline Instance.
     * @param int $count Number.
     * @param \stdClass|null $group Group, defaults to the own one.
     * @return \stdClass[]
     */
    private function sharing_members(\stdClass $sideline, int $count, ?\stdClass $group = null): array {
        global $DB;
        $gen = $this->getDataGenerator();
        $users = [];
        // The current record: creating groups may have raised the group version (issue #9).
        $sideline = $DB->get_record('sideline', ['id' => $sideline->id]);
        $groupid = (int) ($group ?? $this->own)->id;
        if (!isset($this->bystanders[$groupid])) {
            // A member who does not share: no statement may cover every other member of a group.
            $this->bystanders[$groupid] = $gen->create_and_enrol($this->course, 'student');
            $gen->create_group_member(['groupid' => $groupid, 'userid' => $this->bystanders[$groupid]->id]);
        }
        for ($i = 0; $i < $count; $i++) {
            $user = $gen->create_and_enrol($this->course, 'student');
            $gen->create_group_member(['groupid' => ($group ?? $this->own)->id, 'userid' => $user->id]);
            sharing_manager::record($sideline, (int) $user->id, true, false, 1, time());
            $users[] = $user;
        }
        return $users;
    }

    /**
     * An event of a user on an activity, yesterday.
     *
     * @param \stdClass $user User.
     * @param \stdClass $activity Activity.
     * @param string $type completed, passed, failed or viewed.
     * @param int|null $time Time, defaults to yesterday.
     */
    private function event(\stdClass $user, \stdClass $activity, string $type, ?int $time = null): void {
        global $DB;
        $time = $time ?? group_service::day_start(time()) - HOURSECS;
        if ($type === 'viewed') {
            $DB->insert_record('course_modules_viewed', (object) ['coursemoduleid' => $activity->cmid, 'userid' => $user->id,
                'timecreated' => $time]);
            return;
        }
        $states = ['completed' => COMPLETION_COMPLETE, 'passed' => COMPLETION_COMPLETE_PASS, 'failed' => COMPLETION_COMPLETE_FAIL];
        $DB->insert_record('course_modules_completion', (object) ['coursemoduleid' => $activity->cmid, 'userid' => $user->id,
            'completionstate' => $states[$type], 'overrideby' => null, 'timemodified' => $time]);
    }

    /**
     * Instance in closed mode.
     *
     * @param array $options Options.
     * @return \stdClass
     */
    private function coach(array $options = []): \stdClass {
        global $DB;
        $instance = $this->getDataGenerator()->create_module('sideline', ['course' => $this->course->id,
            'sharingmode' => 'closed', 'grouppublish' => 'general', 'groupminsize' => 3] + $options);
        return $DB->get_record('sideline', ['id' => $instance->id]);
    }

    /**
     * Statements for the recipient.
     *
     * @param \stdClass $sideline Instance.
     * @param int|null $now Time.
     * @return array
     */
    private function facts(\stdClass $sideline, ?int $now = null): array {
        global $DB;
        $fresh = $DB->get_record('sideline', ['id' => $sideline->id]);
        $cms = get_fast_modinfo($this->course, (int) $this->recipient->id)->get_cms();
        return (new group_service())->facts($fresh, (int) $this->recipient->id, $cms, $now ?? time())[0];
    }

    public function test_event_types_are_configurable_and_failures_are_never_told(): void {
        $sideline = $this->coach(['groupevents' => 'viewed,passed']);
        $people = $this->sharing_members($sideline, 3);
        foreach ($people as $user) {
            $this->event($user, $this->page, 'completed');
            $this->event($user, $this->quiz, 'failed');
        }
        $this->assertSame([], $this->facts($sideline), 'Completions are not configured, failures never count');
        foreach ($people as $user) {
            $this->event($user, $this->page, 'viewed');
        }
        $facts = $this->facts($sideline);
        $this->assertSame('viewed', $facts[0]['event']);
        $this->assertSame('few', $facts[0]['band']);
        $this->assertArrayNotHasKey('count', $facts[0], 'No exact number');
    }

    public function test_a_pass_is_told_as_a_pass_and_the_ai_may_say_so(): void {
        $sideline = $this->coach(['groupevents' => 'completed,passed']);
        foreach ($this->sharing_members($sideline, 3) as $user) {
            $this->event($user, $this->quiz, 'passed');
        }
        $facts = $this->facts($sideline);
        $this->assertSame('passed', $facts[0]['event']);
        $this->assertSame('passed', $facts[0]['outcome']);
    }

    public function test_two_people_are_never_enough_and_counts_are_banded(): void {
        $sideline = $this->coach(['groupminsize' => 2]);
        $this->assertEquals(3, $sideline->groupminsize, 'The minimum is three');
        $people = $this->sharing_members($sideline, 2);
        foreach ($people as $user) {
            $this->event($user, $this->page, 'completed');
        }
        $this->assertSame([], $this->facts($sideline));
        $more = $this->sharing_members($sideline, 10);
        foreach ($more as $user) {
            $this->event($user, $this->page, 'completed');
        }
        $this->assertSame('many', $this->facts($sideline)[0]['band']);
        $this->assertSame('few', group_service::band(3));
        $this->assertSame('several', group_service::band(6));
    }

    public function test_the_statement_does_not_change_within_a_day(): void {
        $sideline = $this->coach();
        $people = $this->sharing_members($sideline, 4);
        foreach (array_slice($people, 0, 3) as $user) {
            $this->event($user, $this->page, 'completed');
        }
        $before = $this->facts($sideline);
        // A fourth person completes today: a second visit today must not reveal it (sequential differencing).
        $this->event($people[3], $this->page, 'completed', time());
        $this->assertSame($before, $this->facts($sideline));
        // Tomorrow it is part of the window.
        $tomorrow = group_service::day_start(time()) + DAYSECS + HOURSECS;
        $this->assertNotEmpty($this->facts($sideline, $tomorrow));
    }

    public function test_hidden_membership_and_shared_members_of_overlapping_groups(): void {
        global $DB;
        $sideline = $this->coach();
        $second = $this->getDataGenerator()->create_group(['courseid' => $this->course->id, 'name' => 'Green team']);
        $people = $this->sharing_members($sideline, 3);
        $this->getDataGenerator()->create_group_member(['groupid' => $second->id, 'userid' => $this->recipient->id]);
        foreach ($people as $user) {
            $this->getDataGenerator()->create_group_member(['groupid' => $second->id, 'userid' => $user->id]);
            $this->event($user, $this->page, 'completed');
        }
        $this->assertCount(1, $this->facts($sideline), 'One statement, not one per overlapping group');
        // Membership no longer visible to members: nothing may be said about them.
        foreach ($DB->get_records('groups', ['courseid' => $this->course->id]) as $group) {
            $group->visibility = GROUPS_VISIBILITY_OWN;
            groups_update_group($group);
        }
        $this->assertSame([], $this->facts($sideline));
    }

    public function test_queries_do_not_grow_with_groups_or_members(): void {
        global $DB;
        $sideline = $this->coach();
        foreach ($this->sharing_members($sideline, 3) as $user) {
            $this->event($user, $this->page, 'completed');
        }
        $reads = $DB->perf_get_reads();
        $this->facts($sideline);
        $small = $DB->perf_get_reads() - $reads;

        $gen = $this->getDataGenerator();
        for ($g = 0; $g < 10; $g++) {
            $group = $gen->create_group(['courseid' => $this->course->id]);
            $gen->create_group_member(['groupid' => $group->id, 'userid' => $this->recipient->id]);
            foreach ($this->sharing_members($sideline, 5, $group) as $user) {
                $this->event($user, $this->page, 'completed');
            }
        }
        $reads = $DB->perf_get_reads();
        $this->facts($sideline);
        $large = $DB->perf_get_reads() - $reads;
        $this->assertLessThanOrEqual($small + 2, $large, "No query per group or member ($small vs $large)");
    }

    public function test_sharing_users_checks_consent_in_one_query(): void {
        global $DB;
        $sideline = $this->coach(['consentrequired' => 1]);
        $people = $this->sharing_members($sideline, 20);
        foreach (array_slice($people, 0, 10) as $user) {
            consent_manager::record_decision($sideline, (int) $user->id, true, 1, time());
        }
        $fresh = $DB->get_record('sideline', ['id' => $sideline->id]);
        $reads = $DB->perf_get_reads();
        $sharing = sharing_manager::sharing_users($fresh, array_map(static fn($u) => (int) $u->id, $people));
        $this->assertLessThanOrEqual(2, $DB->perf_get_reads() - $reads);
        $this->assertCount(10, $sharing, 'Only those whose consent allows processing');
    }

    public function test_adding_a_group_asks_sharing_learners_again_removing_one_does_not(): void {
        global $DB;
        $sideline = $this->coach();
        instance_config::save_groups((int) $sideline->id, (int) $this->course->id, [(int) $this->own->id], []);
        $peer = $this->sharing_members($DB->get_record('sideline', ['id' => $sideline->id]), 1)[0];
        $fresh = $DB->get_record('sideline', ['id' => $sideline->id]);
        $this->assertTrue(sharing_manager::status($fresh, (int) $peer->id)['publish']);

        $second = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $this->assertTrue(instance_config::save_groups(
            (int) $sideline->id,
            (int) $this->course->id,
            [(int) $this->own->id, (int) $second->id],
            []
        ));
        $fresh = $DB->get_record('sideline', ['id' => $sideline->id]);
        $this->assertTrue(sharing_manager::status($fresh, (int) $peer->id)['reconsent'], 'A new group widens the circle');

        sharing_manager::record($fresh, (int) $peer->id, true, false, 1, time());
        $this->assertFalse(instance_config::save_groups((int) $sideline->id, (int) $this->course->id, [(int) $this->own->id], []));
        $fresh = $DB->get_record('sideline', ['id' => $sideline->id]);
        $this->assertTrue(sharing_manager::status($fresh, (int) $peer->id)['publish'], 'Removing a group narrows it');
    }

    public function test_a_grouping_that_gets_a_new_group_asks_again(): void {
        global $DB;
        $sideline = $this->coach();
        $grouping = $this->getDataGenerator()->create_grouping(['courseid' => $this->course->id]);
        $this->getDataGenerator()->create_grouping_group(['groupingid' => $grouping->id, 'groupid' => $this->own->id]);
        instance_config::save_groups((int) $sideline->id, (int) $this->course->id, [], [(int) $grouping->id]);
        $version = (int) $DB->get_field('sideline', 'groupversion', ['id' => $sideline->id]);
        $new = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        // The observer of grouping_group_assigned compares the circle.
        groups_assign_grouping($grouping->id, $new->id);
        $this->assertSame($version + 1, (int) $DB->get_field('sideline', 'groupversion', ['id' => $sideline->id]));
    }

    public function test_a_new_group_when_all_groups_are_admitted_asks_again(): void {
        global $DB;
        $sideline = $this->coach();
        instance_config::refresh_group_circle((int) $sideline->id);
        $version = (int) $DB->get_field('sideline', 'groupversion', ['id' => $sideline->id]);
        $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $this->assertSame($version + 1, (int) $DB->get_field('sideline', 'groupversion', ['id' => $sideline->id]));
    }

    public function test_a_new_event_type_asks_again(): void {
        global $DB;
        $sideline = $this->coach();
        $old = clone $sideline;
        $new = clone $sideline;
        $new->groupevents = 'completed,viewed';
        instance_config::apply_version_bumps($old, $new);
        $this->assertSame((int) $old->groupversion + 1, $new->groupversion);
        $narrow = clone $sideline;
        $narrow->groupevents = 'completed';
        instance_config::apply_version_bumps($new, $narrow);
        $this->assertSame((int) $new->groupversion, $narrow->groupversion);
    }

    public function test_a_change_during_ai_generation_delivers_no_statement_of_the_old_circle(): void {
        global $DB;
        set_config('aienabled', 1, 'mod_sideline');
        $sideline = $this->coach(['aienabled' => 1]);
        foreach ($this->sharing_members($sideline, 3) as $user) {
            $this->event($user, $this->page, 'completed');
        }
        $fake = new fake_text_generator();
        $echo = fake_text_generator::echoing_tokens();
        $courseid = (int) $this->course->id;
        $first = true;
        $fake->responder = function (string $prompt) use ($echo, $courseid, &$first) {
            if ($first) {
                $first = false;
                // A teacher admits a further group while the text is generated.
                $this->getDataGenerator()->create_group(['courseid' => $courseid]);
            }
            return $echo($prompt);
        };
        $this->setUser($this->recipient);
        $cm = get_coursemodule_from_instance('sideline', $sideline->id);
        $result = (new commentary_service(new ai_gateway($fake)))->get(
            $DB->get_record('sideline', ['id' => $sideline->id]),
            $this->course,
            $this->recipient,
            \context_module::instance($cm->id),
            time()
        );
        $keys = array_column($result['commentary']['blocks'], 'key');
        $this->assertNotContains('group_together', $keys, 'Consents for the old circle are no longer current');
    }

    public function test_a_statement_never_covers_every_other_member_of_the_group(): void {
        global $DB;
        $sideline = $this->coach();
        $gen = $this->getDataGenerator();
        // Rita and three others, all of whom share and completed: "a few others" would name each of them.
        $small = $gen->create_group(['courseid' => $this->course->id, 'name' => 'Small team']);
        groups_remove_member($this->own->id, $this->recipient->id);
        $gen->create_group_member(['groupid' => $small->id, 'userid' => $this->recipient->id]);
        $sideline = $DB->get_record('sideline', ['id' => $sideline->id]);
        foreach ([1, 2, 3] as $i) {
            $user = $gen->create_and_enrol($this->course, 'student');
            $gen->create_group_member(['groupid' => $small->id, 'userid' => $user->id]);
            sharing_manager::record($sideline, (int) $user->id, true, false, 1, time());
            $this->event($user, $this->page, 'completed');
        }
        $facts = $this->facts($sideline);
        $this->assertSame([], $facts);

        // One more member who did not complete: now the statement says nothing about any single person.
        $other = $gen->create_and_enrol($this->course, 'student');
        $gen->create_group_member(['groupid' => $small->id, 'userid' => $other->id]);
        $facts = $this->facts($DB->get_record('sideline', ['id' => $sideline->id]));
        $this->assertCount(1, $facts);
        $this->assertSame('few', $facts[0]['band']);
    }

    public function test_a_completion_stamped_again_today_does_not_change_the_statement_of_the_day(): void {
        global $DB;
        $sideline = $this->coach();
        $members = $this->sharing_members($sideline, 3);
        foreach ($members as $member) {
            $this->event($member, $this->page, 'completed');
        }
        $morning = $this->facts($sideline);
        $this->assertCount(1, $morning);

        // A member ticks the completion off and on again: Moodle stamps it with the current time.
        $DB->set_field('course_modules_completion', 'timemodified', time(), ['coursemoduleid' => $this->page->cmid,
            'userid' => $members[0]->id]);
        $later = $this->facts($sideline);
        $this->assertSame($morning, $later, 'The same statement for the whole day');

        // Who stops sharing drops out at once, even within the day.
        foreach ([1, 2] as $i) {
            sharing_manager::record(
                $DB->get_record('sideline', ['id' => $sideline->id]),
                (int) $members[$i]->id,
                false,
                false,
                1,
                time()
            );
        }
        $after = $this->facts($DB->get_record('sideline', ['id' => $sideline->id]));
        $this->assertSame([], $after);
    }
}
