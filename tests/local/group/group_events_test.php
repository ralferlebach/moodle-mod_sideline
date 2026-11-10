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

use mod_sideline\local\instance_config;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/sideline/lib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/group/lib.php');

#[\PHPUnit\Framework\Attributes\CoversClass(group_service::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(instance_config::class)]
/**
 * The event matrix of group statements (issue #6): use of an activity and improved results as events, the time
 * window, and the unknown state when the site has no data for an event type.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_sideline\local\group\group_service
 * @covers \mod_sideline\local\instance_config
 */
final class group_events_test extends \advanced_testcase {
    /** @var \stdClass Course. */
    private \stdClass $course;
    /** @var \stdClass Recipient. */
    private \stdClass $recipient;
    /** @var \stdClass Graded activity. */
    private \stdClass $assign;
    /** @var \stdClass Instance. */
    private \stdClass $sideline;
    /** @var \stdClass[] Sharing members. */
    private array $members = [];
    /** @var \stdClass Group. */
    private \stdClass $group;
    /** @var \stdClass Member who does not share. */
    private \stdClass $bystander;
    /** @var int Time of the statements. */
    private int $now;

    #[\Override]
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        $gen = $this->getDataGenerator();
        // Noon of today: a fixed distance from both ends of the day.
        $this->now = group_service::day_start(time()) + 12 * HOURSECS;
        $this->course = $gen->create_course();
        $this->assign = $gen->create_module('assign', ['course' => $this->course->id, 'name' => 'Essay one', 'grade' => 100]);
        $group = $gen->create_group(['courseid' => $this->course->id, 'name' => 'Red team']);
        $this->group = $group;
        $this->recipient = $gen->create_and_enrol($this->course, 'student', ['firstname' => 'Rita']);
        $gen->create_group_member(['groupid' => $group->id, 'userid' => $this->recipient->id]);
        $instance = $gen->create_module('sideline', ['course' => $this->course->id, 'sharingmode' => 'closed',
            'grouppublish' => 'named', 'groupminsize' => 3, 'groupevents' => 'active,improved']);
        $this->sideline = $DB->get_record('sideline', ['id' => $instance->id]);
        // A member who does not share: a statement never covers every other member.
        $bystander = $gen->create_and_enrol($this->course, 'student');
        $gen->create_group_member(['groupid' => $group->id, 'userid' => $bystander->id]);
        $this->bystander = $bystander;
        foreach (['Anna', 'Ben', 'Cleo', 'Dan'] as $name) {
            $user = $gen->create_and_enrol($this->course, 'student', ['firstname' => $name]);
            $gen->create_group_member(['groupid' => $group->id, 'userid' => $user->id]);
            sharing_manager::record($this->sideline, (int) $user->id, true, true, 1, $this->now);
            $this->members[] = $user;
        }
    }

    /**
     * Change settings of the instance.
     *
     * @param array $settings Field => value.
     */
    private function configure(array $settings): void {
        global $DB;
        $DB->update_record('sideline', (object) (['id' => $this->sideline->id] + $settings));
        $this->sideline = $DB->get_record('sideline', ['id' => $this->sideline->id]);
    }

    /**
     * The statement for the recipient, or null.
     *
     * @param int|null $now Time.
     * @return array|null
     */
    private function statement(?int $now = null): ?array {
        $cms = get_fast_modinfo($this->course, (int) $this->recipient->id)->get_cms();
        return (new group_service())->facts($this->sideline, (int) $this->recipient->id, $cms, $now ?? $this->now)[0][0] ?? null;
    }

    /**
     * A change of a person in the activity, as the standard log records it.
     *
     * @param \stdClass $user Person.
     * @param int $time Time.
     * @param string $crud c, r, u or d.
     * @param int $edulevel Educational level.
     * @param int $anonymous Whether core logs the event as anonymous.
     * @param int|null $realuserid Somebody logged in as the person.
     */
    private function log(
        \stdClass $user,
        int $time,
        string $crud = 'c',
        int $edulevel = \core\event\base::LEVEL_PARTICIPATING,
        int $anonymous = 0,
        ?int $realuserid = null,
    ): void {
        global $DB;
        $DB->insert_record('logstore_standard_log', (object) [
            'eventname' => '\\mod_assign\\event\\assessable_submitted', 'component' => 'mod_assign', 'action' => 'submitted',
            'target' => 'assessable', 'crud' => $crud, 'edulevel' => $edulevel,
            'contextid' => \context_module::instance($this->assign->cmid)->id, 'contextlevel' => CONTEXT_MODULE,
            'contextinstanceid' => $this->assign->cmid, 'userid' => $user->id, 'courseid' => $this->course->id,
            'anonymous' => $anonymous, 'realuserid' => $realuserid, 'timecreated' => $time, 'origin' => 'web',
        ]);
    }

    /**
     * Grades of a person in the activity over time, as the grade history records them, and the current grade.
     *
     * @param \stdClass $user Person.
     * @param array $grades Time => final grade.
     * @param int $hidden Hidden state of the current grade (0, 1 or a time until which it is hidden).
     */
    private function grades(\stdClass $user, array $grades, int $hidden = 0): void {
        global $DB;
        $item = $this->gradeitem();
        foreach ($grades as $time => $grade) {
            $DB->insert_record('grade_grades_history', (object) ['action' => 2, 'oldid' => 0, 'source' => 'mod/assign',
                'timemodified' => $time, 'loggeduser' => 2, 'itemid' => $item->id, 'userid' => $user->id,
                'finalgrade' => $grade, 'rawgrademax' => 100, 'rawgrademin' => 0, 'hidden' => 0, 'locked' => 0,
                'locktime' => 0, 'exported' => 0, 'overridden' => 0, 'excluded' => 0, 'informationformat' => 0,
                'feedbackformat' => 0]);
        }
        $current = $DB->get_record('grade_grades', ['itemid' => $item->id, 'userid' => $user->id]);
        $row = ['itemid' => $item->id, 'userid' => $user->id, 'finalgrade' => end($grades), 'rawgrademax' => 100,
            'rawgrademin' => 0, 'hidden' => $hidden, 'timemodified' => array_key_last($grades)];
        if ($current) {
            $DB->update_record('grade_grades', (object) (['id' => $current->id] + $row));
        } else {
            $DB->insert_record('grade_grades', (object) $row);
        }
    }

    /**
     * The grade item of the assignment.
     *
     * @return \grade_item
     */
    private function gradeitem(): \grade_item {
        return \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $this->assign->id,
            'itemnumber' => 0, 'courseid' => $this->course->id]);
    }

    /**
     * Three members whose grade rose yesterday.
     */
    private function three_improvements(): void {
        $daystart = group_service::day_start($this->now);
        foreach (array_slice($this->members, 0, 3) as $user) {
            $this->grades($user, [$daystart - 3 * DAYSECS => 30.0, $daystart - HOURSECS => 60.0]);
        }
    }

    public function test_use_of_an_activity_is_told_from_the_standard_log(): void {
        $this->configure(['groupevents' => 'active', 'grouppublish' => 'general']);
        $yesterday = group_service::day_start($this->now) - HOURSECS;
        foreach (array_slice($this->members, 0, 3) as $user) {
            $this->log($user, $yesterday);
        }
        $statement = $this->statement();
        $this->assertNotNull($statement);
        $this->assertSame('active', $statement['event']);
        $this->assertSame('group_together', $statement['key']);
        $this->assertSame('few', $statement['band']);
        $this->assertArrayNotHasKey('names', $statement);
    }

    public function test_reading_and_teaching_events_are_no_use(): void {
        $this->configure(['groupevents' => 'active', 'grouppublish' => 'general']);
        $yesterday = group_service::day_start($this->now) - HOURSECS;
        [$a, $b, $c] = $this->members;
        $this->log($a, $yesterday);
        $this->log($b, $yesterday, 'r');
        $this->log($c, $yesterday, 'u', \core\event\base::LEVEL_TEACHING);
        $this->assertNull($this->statement(), 'Only changes by a participant count');
    }

    public function test_named_use_needs_the_named_consent_and_takes_the_latest_change(): void {
        $this->configure(['groupevents' => 'active', 'grouppublish' => 'named']);
        [$anna, $ben] = $this->members;
        // Ben shares, but not with his name: he worked today too and is never named.
        sharing_manager::record($this->sideline, (int) $ben->id, true, false, 1, $this->now);
        $this->log($ben, $this->now - HOURSECS);
        // Work at the far end of the anonymous window, outside the named one, and again today: the latest change
        // decides a named statement.
        $this->log($anna, group_service::day_start($this->now) - WEEKSECS + MINSECS);
        $this->log($anna, $this->now - HOURSECS);
        $statement = $this->statement();
        $this->assertSame('group_named', $statement['key']);
        $this->assertSame(['Anna'], array_values($statement['names']));
    }

    public function test_an_improved_result_is_told_only_anonymously_and_never_a_worse_one(): void {
        $this->configure(['groupevents' => 'improved', 'grouppublish' => 'named']);
        $daystart = group_service::day_start($this->now);
        [$anna, $ben, $cleo, $dan] = $this->members;
        $this->grades($anna, [$daystart - 5 * DAYSECS => 40.0, $daystart - HOURSECS => 70.0]);
        $this->grades($ben, [$daystart - 4 * DAYSECS => 55.0, $daystart - 2 * HOURSECS => 80.0]);
        // Worse than before: never an event.
        $this->grades($cleo, [$daystart - 4 * DAYSECS => 90.0, $daystart - HOURSECS => 60.0]);
        // A first grade: no earlier one, no improvement.
        $this->grades($dan, [$daystart - HOURSECS => 95.0]);
        $this->assertNull($this->statement(), 'Two improvements are below the minimum size');

        $this->grades($cleo, [$daystart - 30 * MINSECS => 97.5]);
        $statement = $this->statement();
        $this->assertSame('improved', $statement['event']);
        $this->assertSame('group_together', $statement['key'], 'Named consent of all: still anonymous');
        $this->assertArrayNotHasKey('names', $statement);
        $this->assertArrayNotHasKey('grade', $statement);
        $this->assertSame('few', $statement['band']);
        $this->assertStringNotContainsString('97.5', json_encode($statement), 'No grade reaches the statement');
    }

    public function test_the_time_window_bounds_every_event_type(): void {
        $this->configure(['groupevents' => 'active', 'grouppublish' => 'general', 'groupwindow' => 3 * DAYSECS]);
        $daystart = group_service::day_start($this->now);
        foreach (array_slice($this->members, 0, 3) as $user) {
            $this->log($user, $daystart - 5 * DAYSECS);
        }
        $this->assertNull($this->statement(), 'Five days ago is outside a window of three days');
        $this->configure(['groupwindow' => WEEKSECS]);
        $this->assertSame('active', $this->statement()['event']);
        // The same for improved results.
        $this->configure(['groupevents' => 'improved', 'groupwindow' => 3 * DAYSECS]);
        foreach (array_slice($this->members, 0, 3) as $user) {
            $this->grades($user, [$daystart - 9 * DAYSECS => 20.0, $daystart - 5 * DAYSECS => 50.0]);
        }
        $this->assertNull($this->statement(), 'An improvement five days ago is outside a window of three days');
        $this->configure(['groupwindow' => WEEKSECS]);
        $this->assertSame('improved', $this->statement()['event']);
        $this->configure(['groupwindow' => 12345]);
        $this->assertSame(WEEKSECS, instance_config::group_window($this->sideline), 'Unknown values fall back to a week');
    }

    public function test_event_types_without_data_on_the_site_are_unknown_and_never_told(): void {
        global $CFG;
        $this->configure(['groupevents' => 'active,improved', 'grouppublish' => 'general']);
        $yesterday = group_service::day_start($this->now) - HOURSECS;
        foreach (array_slice($this->members, 0, 3) as $user) {
            $this->log($user, $yesterday);
        }
        $this->assertSame([], instance_config::unknown_group_events($this->sideline));
        $this->assertSame('active', $this->statement()['event']);

        set_config('enabled_stores', 'logstore_database', 'tool_log');
        $CFG->disablegradehistory = 1;
        $this->assertSame(['active', 'improved'], instance_config::unknown_group_events($this->sideline));
        $this->assertNull($this->statement(), 'Old rows of a switched-off log are not used either');

        // Not configured: never reported as unknown.
        $this->configure(['groupevents' => 'completed']);
        $this->assertSame([], instance_config::unknown_group_events($this->sideline));
    }

    public function test_a_new_event_type_or_a_longer_window_requires_new_decisions(): void {
        $old = clone $this->sideline;
        $cases = [
            'new type' => [['groupevents' => 'active,improved,completed'], 1],
            'fewer types' => [['groupevents' => 'active'], 0],
            'longer window' => [['groupwindow' => 2 * WEEKSECS], 1],
            'shorter window' => [['groupwindow' => DAYSECS], 0],
        ];
        foreach ($cases as $name => [$change, $bump]) {
            $new = (object) ($change + (array) $old);
            instance_config::apply_version_bumps($old, $new);
            $this->assertSame((int) $old->groupversion + $bump, $new->groupversion, $name);
        }

        // After the bump, a person's earlier decision no longer counts.
        global $DB;
        $yesterday = group_service::day_start($this->now) - HOURSECS;
        $this->configure(['groupevents' => 'active', 'grouppublish' => 'general']);
        foreach (array_slice($this->members, 0, 3) as $user) {
            $this->log($user, $yesterday);
        }
        $this->assertNotNull($this->statement());
        $this->configure(['groupversion' => (int) $this->sideline->groupversion + 1]);
        $this->assertNull($this->statement(), 'Nobody has decided for the new version yet');
        $this->assertSame(0, $DB->count_records_select(
            'sideline_publish',
            'sidelineid = ? AND infoversion >= ?',
            [$this->sideline->id, $this->sideline->groupversion]
        ));
    }

    public function test_improvements_of_every_other_member_are_never_told(): void {
        $this->configure(['groupevents' => 'improved', 'grouppublish' => 'general']);
        $daystart = group_service::day_start($this->now);
        [$anna, $ben, $cleo, $dan] = $this->members;
        foreach ([$anna, $ben, $cleo] as $user) {
            $this->grades($user, [$daystart - 3 * DAYSECS => 30.0, $daystart - HOURSECS => 60.0]);
        }
        // A group of the recipient and exactly the three who improved, as a teacher could arrange it.
        groups_remove_member($this->group->id, $this->bystander->id);
        groups_remove_member($this->group->id, $dan->id);
        $this->assertNull($this->statement(), 'All three others improved: the statement would name each of them');

        groups_add_member($this->group->id, $dan->id);
        $this->assertSame('improved', $this->statement()['event'], 'Three of four others: told anonymously');
    }

    public function test_an_improvement_is_a_new_best_never_a_lower_or_repeated_grade(): void {
        $this->configure(['groupevents' => 'improved', 'grouppublish' => 'general', 'groupwindow' => 3 * DAYSECS]);
        $daystart = group_service::day_start($this->now);
        [$anna, $ben, $cleo, $dan] = $this->members;
        // Anna: 50, then 80, then 60 yesterday. 60 is above 50, but below her best: no improvement.
        $this->grades($anna, [$daystart - 5 * DAYSECS => 50.0, $daystart - 5 * DAYSECS + HOURSECS => 80.0,
            $daystart - HOURSECS => 60.0]);
        // Ben: 40, then 70, then the same 70 saved again yesterday (new feedback): no new improvement.
        $this->grades($ben, [$daystart - 5 * DAYSECS => 40.0, $daystart - 5 * DAYSECS + HOURSECS => 70.0,
            $daystart - HOURSECS => 70.0]);
        foreach ([$cleo, $dan] as $user) {
            $this->grades($user, [$daystart - 5 * DAYSECS => 30.0, $daystart - HOURSECS => 60.0]);
        }
        $this->assertNull($this->statement(), 'Only Cleo and Dan improved: below the minimum size');

        // Anna above her best of 80: three improvements.
        $this->grades($anna, [$daystart - 30 * MINSECS => 90.0]);
        $this->assertSame('improved', $this->statement()['event']);
    }

    public function test_hidden_grades_are_never_told(): void {
        global $DB;
        $this->configure(['groupevents' => 'improved', 'grouppublish' => 'general']);
        $this->three_improvements();
        $item = $this->gradeitem();
        $DB->set_field('grade_items', 'hidden', 1, ['id' => $item->id]);
        $this->assertNull($this->statement(), 'The grade item is hidden');
        $DB->set_field('grade_items', 'hidden', $this->now + DAYSECS, ['id' => $item->id]);
        $this->assertNull($this->statement(), 'The grade item is hidden until tomorrow');
        $DB->set_field('grade_items', 'hidden', $this->now - DAYSECS, ['id' => $item->id]);
        $this->assertSame('improved', $this->statement()['event'], 'Hidden until yesterday: visible now');

        // The course shows no grades to learners at all.
        $DB->set_field('course', 'showgrades', 0, ['id' => $this->course->id]);
        $this->assertNull($this->statement(), 'Learners see no grades in this course');
        $DB->set_field('course', 'showgrades', 1, ['id' => $this->course->id]);
        $this->assertSame('improved', $this->statement()['event']);

        // The grade of one of the three is hidden: two are left, below the minimum size.
        $DB->set_field('grade_grades', 'hidden', 1, ['itemid' => $item->id, 'userid' => $this->members[0]->id]);
        $this->assertNull($this->statement());
    }

    public function test_anonymous_events_and_actions_of_somebody_logged_in_as_the_person_are_no_use(): void {
        $this->configure(['groupevents' => 'active', 'grouppublish' => 'general']);
        $yesterday = group_service::day_start($this->now) - HOURSECS;
        [$anna, $ben, $cleo] = $this->members;
        // An anonymous feedback, a submission under blind marking: core logs them as anonymous.
        $this->log($anna, $yesterday, 'c', \core\event\base::LEVEL_PARTICIPATING, 1);
        // A teacher logged in as Ben.
        $this->log($ben, $yesterday, 'c', \core\event\base::LEVEL_PARTICIPATING, 0, (int) get_admin()->id);
        $this->log($cleo, $yesterday);
        $this->assertNull($this->statement());

        $this->log($anna, $yesterday);
        $this->log($ben, $yesterday);
        $this->assertSame('active', $this->statement()['event']);
    }

    public function test_the_new_event_types_in_open_mode_tell_about_another_group_only_anonymously(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $blue = $gen->create_group(['courseid' => $this->course->id, 'name' => 'Blue team']);
        $this->configure(['groupevents' => 'active,improved', 'sharingmode' => 'open', 'grouppublish' => 'named_with_group']);
        $daystart = group_service::day_start($this->now);
        $others = [];
        foreach (['Eve', 'Finn', 'Gus', 'Hal'] as $name) {
            $user = $gen->create_and_enrol($this->course, 'student', ['firstname' => $name]);
            $gen->create_group_member(['groupid' => $blue->id, 'userid' => $user->id]);
            $others[] = $user;
        }
        // Sharing decisions for the circle that now includes the other group.
        $this->sideline = $DB->get_record('sideline', ['id' => $this->sideline->id]);
        foreach ($others as $user) {
            sharing_manager::record($this->sideline, (int) $user->id, true, true, 1, $this->now);
        }
        foreach (array_slice($others, 0, 3) as $user) {
            $this->grades($user, [$daystart - 3 * DAYSECS => 30.0, $daystart - HOURSECS => 60.0]);
        }
        $statement = $this->statement();
        $this->assertSame('improved', $statement['event']);
        $this->assertSame('group_only', $statement['key'], 'Named with group, but an improvement names nobody');
        $this->assertSame('Blue team', $statement['group']);
        $this->assertArrayNotHasKey('names', $statement);

        // Use in the other group: named with the group, as the level allows.
        $this->configure(['groupevents' => 'active']);
        $this->log($others[3], $this->now - HOURSECS);
        $statement = $this->statement();
        $this->assertSame('group_named_with_group', $statement['key']);
        $this->assertSame(['Hal'], array_values($statement['names']));
    }
}
