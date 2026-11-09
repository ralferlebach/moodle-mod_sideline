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

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use mod_sideline\local\consent\consent_manager;
use mod_sideline\privacy\provider;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/sideline/lib.php');
require_once($CFG->dirroot . '/course/lib.php');

#[\PHPUnit\Framework\Attributes\CoversClass(day_count::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(group_service::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(sharing_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_sideline\local\observer::class)]
/**
 * The day counts of anonymous group statements (issues #29, #30): a person counted once today stays counted
 * for the day, and nothing about a person is kept after they stop sharing, withdraw, leave the group or the
 * course, or a privacy request deletes their data, in whatever mode the instance is.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_sideline\local\group\day_count
 * @covers \mod_sideline\local\group\group_service
 * @covers \mod_sideline\local\group\sharing_manager
 * @covers \mod_sideline\privacy\provider
 * @covers \mod_sideline\local\observer
 */
final class day_count_test extends \advanced_testcase {
    /** @var \stdClass Course. */
    private \stdClass $course;
    /** @var \stdClass Recipient. */
    private \stdClass $recipient;
    /** @var \stdClass Group. */
    private \stdClass $group;
    /** @var \stdClass Activity. */
    private \stdClass $page;
    /** @var \stdClass Instance. */
    private \stdClass $sideline;
    /** @var int Course module of the instance. */
    private int $cmid;
    /** @var \stdClass[] Sharing members with an event yesterday. */
    private array $members = [];

    #[\Override]
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        set_config('enablecompletion', 1);
        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course(['enablecompletion' => 1]);
        $this->page = $gen->create_module('page', ['course' => $this->course->id, 'name' => 'Unit A',
            'completion' => COMPLETION_TRACKING_MANUAL]);
        $this->group = $gen->create_group(['courseid' => $this->course->id, 'name' => 'Red team']);
        $this->recipient = $gen->create_and_enrol($this->course, 'student');
        $gen->create_group_member(['groupid' => $this->group->id, 'userid' => $this->recipient->id]);
        $instance = $gen->create_module('sideline', ['course' => $this->course->id, 'sharingmode' => 'closed',
            'grouppublish' => 'general', 'groupminsize' => 3]);
        $this->sideline = $DB->get_record('sideline', ['id' => $instance->id]);
        $this->cmid = (int) $instance->cmid;
        // A member who does not share: no statement may cover every other member.
        $bystander = $gen->create_and_enrol($this->course, 'student');
        $gen->create_group_member(['groupid' => $this->group->id, 'userid' => $bystander->id]);
        for ($i = 0; $i < 3; $i++) {
            $user = $gen->create_and_enrol($this->course, 'student');
            $gen->create_group_member(['groupid' => $this->group->id, 'userid' => $user->id]);
            sharing_manager::record($this->fresh(), (int) $user->id, true, false, 1, time());
            $DB->insert_record('course_modules_completion', (object) ['coursemoduleid' => $this->page->cmid,
                'userid' => $user->id, 'completionstate' => COMPLETION_COMPLETE,
                'timemodified' => group_service::day_start(time()) - HOURSECS]);
            $this->members[] = $user;
        }
    }

    /**
     * The current instance record.
     *
     * @return \stdClass
     */
    private function fresh(): \stdClass {
        global $DB;
        return $DB->get_record('sideline', ['id' => $this->sideline->id], '*', MUST_EXIST);
    }

    /**
     * Statements for the recipient.
     *
     * @param int|null $now Time.
     * @return array
     */
    private function facts(?int $now = null): array {
        $cms = get_fast_modinfo($this->course, (int) $this->recipient->id)->get_cms();
        return (new group_service())->facts($this->fresh(), (int) $this->recipient->id, $cms, $now ?? time())[0];
    }

    /**
     * User ids that have a day count in the instance.
     *
     * @return int[]
     */
    private function counted(): array {
        global $DB;
        $ids = array_map('intval', $DB->get_fieldset_select(
            'sideline_daycount',
            'DISTINCT userid',
            'sidelineid = ?',
            [$this->sideline->id]
        ));
        sort($ids);
        return $ids;
    }

    /**
     * Ids of the sharing members.
     *
     * @return int[]
     */
    private function member_ids(): array {
        $ids = array_map(static fn($u) => (int) $u->id, $this->members);
        sort($ids);
        return $ids;
    }

    /**
     * A member ticks the completion off and on again: Moodle stamps it with the current time.
     *
     * @param \stdClass $member Member.
     */
    private function stamp_again(\stdClass $member): void {
        global $DB;
        $DB->set_field('course_modules_completion', 'timemodified', time(), ['coursemoduleid' => $this->page->cmid,
            'userid' => $member->id]);
    }

    public function test_no_cache_of_the_plugin_holds_user_ids(): void {
        $this->assertCount(1, $this->facts());
        $definitions = array_filter(
            array_keys(\cache_config::instance()->get_definitions()),
            static fn(string $id): bool => str_starts_with($id, 'mod_sideline/')
        );
        $this->assertSame([], array_values($definitions), 'The day counts are no cache any more');
        $this->assertSame($this->member_ids(), $this->counted(), 'They are rows of their own table');
    }

    public function test_stopping_to_share_or_withdrawing_removes_the_person_at_once(): void {
        $this->assertCount(1, $this->facts());
        [$first, $second] = $this->members;
        sharing_manager::record($this->fresh(), (int) $first->id, false, false, 1, time());
        $this->assertNotContains((int) $first->id, $this->counted(), 'Stopped sharing');

        consent_manager::record_decision($this->fresh(), (int) $second->id, false, 1, time());
        $this->assertNotContains((int) $second->id, $this->counted(), 'Withdrew consent');
        $this->assertSame([], $this->facts(), 'Below the minimum size: no statement');
    }

    public function test_a_person_who_shares_again_is_not_counted_from_old_rows(): void {
        $this->assertCount(1, $this->facts());
        [$first] = $this->members;
        $this->stamp_again($first);
        $this->assertCount(1, $this->facts(), 'Still counted within the day');

        // Withdraw and opt in again on the same day: the earlier count does not come back.
        sharing_manager::record($this->fresh(), (int) $first->id, false, false, 1, time());
        sharing_manager::record($this->fresh(), (int) $first->id, true, false, 1, time());
        $this->assertSame([], $this->facts(), 'Their completion is from today now, and the old count is gone');
        $this->assertNotContains((int) $first->id, $this->counted());
    }

    public function test_privacy_deletion_removes_the_rows_in_every_mode(): void {
        global $DB;
        $this->assertCount(1, $this->facts());
        [$first, $second, $third] = $this->members;
        $context = \context_module::instance($this->cmid);

        // The mode is individual now and the publication row is gone already.
        $DB->set_field('sideline', 'sharingmode', 'individual', ['id' => $this->sideline->id]);
        $DB->delete_records('sideline_publish', ['sidelineid' => $this->sideline->id, 'userid' => $first->id]);
        provider::delete_data_for_user(new approved_contextlist($first, 'mod_sideline', [$context->id]));
        $this->assertNotContains((int) $first->id, $this->counted());

        provider::delete_data_for_users(new approved_userlist($context, 'mod_sideline', [(int) $second->id]));
        $this->assertNotContains((int) $second->id, $this->counted());

        provider::delete_data_for_all_users_in_context($context);
        $this->assertSame([], $this->counted());
        $this->assertNotContains((int) $third->id, $this->counted());
    }

    public function test_leaving_the_group_or_the_course_removes_the_person(): void {
        $this->assertCount(1, $this->facts());
        [$first, $second] = $this->members;
        groups_remove_member($this->group->id, $first->id);
        $this->assertNotContains((int) $first->id, $this->counted(), 'Left the group');

        $enrol = enrol_get_plugin('manual');
        $instance = array_values(enrol_get_instances($this->course->id, true))[0];
        $enrol->unenrol_user($instance, $second->id);
        $this->assertNotContains((int) $second->id, $this->counted(), 'Left the course');
        $this->assertCount(1, $this->counted());
    }

    public function test_the_end_of_group_mode_in_the_settings_drops_every_count(): void {
        global $DB;
        $this->assertCount(1, $this->facts());
        $data = $this->fresh();
        $data->instance = $data->id;
        $data->coursemodule = $this->cmid;
        $data->sharingmode = 'individual';
        sideline_update_instance($data);
        $this->assertSame([], $this->counted(), 'Group mode ended');

        // A copy of the activity starts without counts; the original keeps none either.
        $DB->set_field('sideline', 'sharingmode', 'closed', ['id' => $this->sideline->id]);
        $this->assertCount(1, $this->facts());
        $this->setAdminUser();
        $actions = \core_courseformat\formatactions::cm($this->course);
        $copy = method_exists($actions, 'duplicate')
            ? $actions->duplicate($this->cmid)
            : duplicate_module($this->course, get_fast_modinfo($this->course)->get_cm($this->cmid));
        $this->assertSame(0, $DB->count_records('sideline_daycount', ['sidelineid' => $copy->instance]));
    }

    public function test_a_wider_circle_an_import_or_a_new_consent_version_drop_every_count(): void {
        global $DB;
        // A new group while all groups are admitted widens the circle (group version).
        $this->assertCount(1, $this->facts());
        $this->assertNotSame([], $this->counted());
        $this->getDataGenerator()->create_group(['courseid' => $this->course->id, 'name' => 'Blue team']);
        $this->assertSame([], $this->counted(), 'Wider circle');

        // An import that raises the level.
        $DB->set_field('sideline', 'groupversion', $this->fresh()->groupversion, ['id' => $this->sideline->id]);
        foreach ($this->members as $member) {
            sharing_manager::record($this->fresh(), (int) $member->id, true, false, 1, time());
        }
        $this->assertCount(1, $this->facts());
        $this->assertNotSame([], $this->counted());
        $this->setAdminUser();
        $data = \mod_sideline\local\transfer\config_transfer::migrate(['format' => 'mod_sideline_config', 'version' => 3,
            'settings' => ['grouppublish' => 'named']]);
        $plan = \mod_sideline\local\transfer\config_transfer::preview($this->fresh(), $data, ['settings'], 'replace')['plan'];
        \mod_sideline\local\transfer\config_transfer::apply($this->fresh(), $plan);
        $this->assertSame([], $this->counted(), 'Import with a wider level');

        // A new consent version: the people decide again.
        $before = $this->fresh();
        $after = clone $before;
        $after->consentversion = (int) $before->consentversion + 1;
        $DB->insert_record('sideline_daycount', (object) ['sidelineid' => $before->id, 'userid' => $this->members[0]->id,
            'groupid' => $this->group->id, 'cmid' => $this->page->cmid, 'eventtype' => 'completed',
            'daystart' => group_service::day_start(time())]);
        $this->assertTrue(day_count::forget_if_terms_changed($before, $after));
        $this->assertSame([], $this->counted(), 'New consent version');
        $this->assertFalse(day_count::forget_if_terms_changed($before, clone $before), 'Nothing changed, nothing deleted');
    }

    public function test_a_deleted_group_or_a_suspended_enrolment_removes_the_rows(): void {
        $this->assertCount(1, $this->facts());
        [$first] = $this->members;
        $enrol = enrol_get_plugin('manual');
        $instance = array_values(enrol_get_instances($this->course->id, true))[0];
        $enrol->update_user_enrol($instance, $first->id, ENROL_USER_SUSPENDED);
        $this->assertNotContains((int) $first->id, $this->counted(), 'Suspended');

        groups_delete_group($this->group->id);
        $this->assertSame([], $this->counted(), 'Group deleted');
    }

    public function test_rows_of_earlier_days_are_not_used_and_are_deleted(): void {
        global $DB;
        $this->assertCount(1, $this->facts());
        [$first] = $this->members;
        // The next day: yesterday's count does not keep a person whose completion was stamped again today.
        $tomorrow = group_service::day_start(time()) + DAYSECS + HOURSECS;
        $DB->set_field('course_modules_completion', 'timemodified', $tomorrow - 60, ['coursemoduleid' => $this->page->cmid,
            'userid' => $first->id]);
        $this->assertSame([], $this->facts($tomorrow), 'Only two people before the next day');
        $this->assertSame(
            0,
            $DB->count_records_select('sideline_daycount', 'daystart < ?', [group_service::day_start($tomorrow)]),
            'The next statement deleted the rows of earlier days'
        );

        // The scheduled task deletes rows of earlier days of every instance.
        $DB->insert_record('sideline_daycount', (object) ['sidelineid' => 999, 'userid' => $first->id, 'groupid' => 1,
            'cmid' => 1, 'eventtype' => 'completed', 'daystart' => group_service::day_start(time()) - DAYSECS]);
        ob_start();
        (new \mod_sideline\task\purge_cache())->execute();
        ob_end_clean();
        $this->assertSame(0, $DB->count_records_select('sideline_daycount', 'daystart < ?', [group_service::day_start(time())]));
    }

    public function test_a_withdrawal_between_the_count_and_its_storage_leaves_no_row(): void {
        global $DB;
        // Another web server node: the count is taken, the person withdraws, then the rows are stored.
        [$first] = $this->members;
        $count = new day_count($this->fresh(), group_service::day_start(time()), [(int) $this->group->id]);
        $count->counted((int) $this->group->id, (int) $this->page->cmid, 'completed', $this->member_ids(), $this->member_ids());
        sharing_manager::record($this->fresh(), (int) $first->id, false, false, 1, time());
        $count->store();
        $this->assertNotContains((int) $first->id, $this->counted(), 'The check after storing removed the row');
        $this->assertCount(2, $this->counted());

        // A second node stores the same rows at the same time: one row each.
        $again = new day_count($this->fresh(), group_service::day_start(time()), [(int) $this->group->id]);
        $again->counted((int) $this->group->id, (int) $this->page->cmid, 'completed', [], $this->member_ids());
        $other = new day_count($this->fresh(), group_service::day_start(time()), [(int) $this->group->id]);
        $other->counted((int) $this->group->id, (int) $this->page->cmid, 'viewed', $this->member_ids(), []);
        $twin = new day_count($this->fresh(), group_service::day_start(time()), [(int) $this->group->id]);
        $twin->counted((int) $this->group->id, (int) $this->page->cmid, 'viewed', $this->member_ids(), []);
        $other->store();
        $twin->store();
        $this->assertSame(2, $DB->count_records('sideline_daycount', ['sidelineid' => $this->sideline->id,
            'eventtype' => 'viewed']));
    }
}
