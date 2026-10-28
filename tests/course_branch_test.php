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

namespace mod_sideline\local\service;

use mod_sideline\local\instance_config;

/**
 * Course branches (categories with subcategories) as sources of a course union (L-19, P-43).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_sideline\local\service\scope_resolver
 * @covers \mod_sideline\local\instance_config
 */
#[\PHPUnit\Framework\Attributes\CoversClass(scope_resolver::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(instance_config::class)]
final class course_branch_test extends \advanced_testcase {
    /** @var \core_course_category Category of the branch. */
    private \core_course_category $branch;
    /** @var \core_course_category Subcategory inside the branch. */
    private \core_course_category $sub;
    /** @var \core_course_category Category outside the branch. */
    private \core_course_category $other;
    /** @var \stdClass The instance's own course. */
    private \stdClass $home;
    /** @var \stdClass Teacher. */
    private \stdClass $teacher;
    /** @var \stdClass Learner. */
    private \stdClass $student;
    /** @var \stdClass Instance record. */
    private \stdClass $sideline;

    #[\Override]
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        set_config('crosscourse', 1, 'mod_sideline');
        $gen = $this->getDataGenerator();
        $this->branch = $gen->create_category(['name' => 'Engineering']);
        $this->sub = $gen->create_category(['name' => 'Year 1', 'parent' => $this->branch->id]);
        $this->other = $gen->create_category(['name' => 'Arts']);
        $this->home = $gen->create_course(['category' => $this->branch->id]);
        $this->teacher = $gen->create_and_enrol($this->home, 'editingteacher');
        $this->student = $gen->create_and_enrol($this->home, 'student');
        // The teacher manages activities in the whole branch, not in the other category.
        $manager = $DB->get_field('role', 'id', ['shortname' => 'manager']);
        role_assign($manager, $this->teacher->id, \context_coursecat::instance($this->branch->id)->id);
        $instance = $gen->create_module('sideline', ['course' => $this->home->id]);
        $this->sideline = $DB->get_record('sideline', ['id' => $instance->id]);
    }

    /**
     * A course with one page, the learner enrolled.
     *
     * @param \core_course_category $category Category.
     * @param string $name Page name.
     * @return \stdClass The page.
     */
    private function course_with_page(\core_course_category $category, string $name): \stdClass {
        $course = $this->getDataGenerator()->create_course(['category' => $category->id]);
        $this->getDataGenerator()->enrol_user($this->student->id, $course->id, 'student');
        return $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => $name]);
    }

    /**
     * Names of the observed activities.
     *
     * @return string[]
     */
    private function observed(): array {
        $cms = (new scope_resolver())->resolve($this->sideline, (int) $this->student->id);
        $names = array_map(static fn(\cm_info $cm) => $cm->name, $cms);
        sort($names);
        return array_values($names);
    }

    public function test_branch_needs_the_right_to_manage_activities_in_the_category(): void {
        instance_config::save_source_categories($this->sideline, [$this->branch->id, $this->other->id], (int) $this->teacher->id);
        $this->assertSame([(int) $this->branch->id], instance_config::get_source_categories((int) $this->sideline->id));

        // Switched off site-wide: nothing is evaluated and saving changes nothing; switching on restores it.
        set_config('crosscourse', 0, 'mod_sideline');
        $this->assertSame([], instance_config::get_source_categories((int) $this->sideline->id));
        instance_config::save_source_categories($this->sideline, [], (int) $this->teacher->id);
        set_config('crosscourse', 1, 'mod_sideline');
        $this->assertSame([(int) $this->branch->id], instance_config::get_source_categories((int) $this->sideline->id));
    }

    public function test_a_co_teacher_saving_keeps_branches_they_cannot_manage(): void {
        global $DB;
        $coteacher = $this->getDataGenerator()->create_and_enrol($this->home, 'editingteacher');
        instance_config::save_source_categories($this->sideline, [$this->branch->id], (int) $this->teacher->id);
        // The co-teacher has no right in the branch: their form does not offer it, saving must not drop it.
        instance_config::save_source_categories($this->sideline, [], (int) $coteacher->id);
        $this->assertSame([(int) $this->branch->id], instance_config::get_source_categories((int) $this->sideline->id));
        $this->assertSame((int) $this->teacher->id, (int) $DB->get_field('sideline_sourcecat', 'usermodified', []));

        // The teacher with the right may remove it.
        instance_config::save_source_categories($this->sideline, [], (int) $this->teacher->id);
        $this->assertSame([], instance_config::get_source_categories((int) $this->sideline->id));
    }

    public function test_losing_the_right_stops_the_evaluation(): void {
        global $DB;
        $page = $this->course_with_page($this->sub, 'Mechanics lab');
        instance_config::save_source_categories($this->sideline, [$this->branch->id], (int) $this->teacher->id);
        $this->assertSame(['Mechanics lab'], $this->observed());

        // A prohibition in the course itself overrides the right in the category.
        $manager = $DB->get_field('role', 'id', ['shortname' => 'manager']);
        assign_capability('moodle/course:manageactivities', CAP_PROHIBIT, $manager, \context_course::instance($page->course)->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertSame([], $this->observed());
        unassign_capability('moodle/course:manageactivities', $manager, \context_course::instance($page->course)->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertSame(['Mechanics lab'], $this->observed());

        // The teacher loses the role in the category: the branch stops (P-43).
        role_unassign($manager, $this->teacher->id, \context_coursecat::instance($this->branch->id)->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertSame([], $this->observed());
    }

    public function test_source_courses_follow_the_same_rules(): void {
        global $DB;
        $page = $this->course_with_page($this->other, 'Painting');
        $coteacher = $this->getDataGenerator()->create_and_enrol($this->home, 'editingteacher');
        $this->getDataGenerator()->enrol_user($this->teacher->id, $page->course, 'editingteacher');
        instance_config::save_sources($this->sideline, [$page->course], (int) $this->teacher->id);
        $this->assertSame(['Painting'], $this->observed());
        instance_config::save_sources($this->sideline, [], (int) $coteacher->id);
        $this->assertSame([(int) $page->course], instance_config::get_sources((int) $this->sideline->id), 'Kept');

        // The authorising teacher leaves the source course: it stops being evaluated.
        $enrol = enrol_get_plugin('manual');
        $instance = $DB->get_record('enrol', ['courseid' => $page->course, 'enrol' => 'manual']);
        $enrol->unenrol_user($instance, $this->teacher->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertSame([], $this->observed());
    }

    public function test_learner_sees_activities_of_branch_courses_with_active_enrolment_only(): void {
        $this->course_with_page($this->sub, 'Mechanics lab');
        $this->course_with_page($this->other, 'Painting');
        $outside = $this->getDataGenerator()->create_course(['category' => $this->sub->id]);
        $this->getDataGenerator()->create_module('page', ['course' => $outside->id, 'name' => 'Not enrolled']);
        instance_config::save_source_categories($this->sideline, [$this->branch->id], (int) $this->teacher->id);

        $this->assertSame(['Mechanics lab'], $this->observed());

        // Switching the course union off stops the evaluation at once.
        set_config('crosscourse', 0, 'mod_sideline');
        $this->assertSame([], $this->observed());
    }

    public function test_unenrolment_and_hidden_courses_stop_the_evaluation(): void {
        global $DB;
        $page = $this->course_with_page($this->sub, 'Mechanics lab');
        instance_config::save_source_categories($this->sideline, [$this->branch->id], (int) $this->teacher->id);
        $this->assertSame(['Mechanics lab'], $this->observed());

        $DB->set_field('course', 'visible', 0, ['id' => $page->course]);
        rebuild_course_cache($page->course, true);
        $this->assertSame([], $this->observed(), 'A hidden course is not accessible for the learner');

        $DB->set_field('course', 'visible', 1, ['id' => $page->course]);
        rebuild_course_cache($page->course, true);
        $enrol = enrol_get_plugin('manual');
        $instance = $DB->get_record('enrol', ['courseid' => $page->course, 'enrol' => 'manual']);
        $enrol->unenrol_user($instance, $this->student->id);
        $this->assertSame([], $this->observed());
    }

    public function test_number_of_branch_courses_per_learner_is_bounded_and_deletion_cleans_up(): void {
        global $DB;
        for ($i = 1; $i <= instance_config::MAX_BRANCH_COURSES + 2; $i++) {
            $this->course_with_page($this->sub, sprintf('Unit %02d', $i));
        }
        instance_config::save_source_categories($this->sideline, [$this->branch->id], (int) $this->teacher->id);
        $this->assertCount(instance_config::MAX_BRANCH_COURSES, $this->observed());

        require_once(\core_component::get_component_directory('mod_sideline') . '/lib.php');
        sideline_delete_instance((int) $this->sideline->id);
        $this->assertSame(0, $DB->count_records('sideline_sourcecat'));
    }
}
