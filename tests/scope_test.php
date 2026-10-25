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
 * Scope: sections with nested subsections, explicit activities and course unions (P1-05).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(scope_resolver::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(instance_config::class)]
final class scope_test extends \advanced_testcase {
    /**
     * Names of the resolved activities.
     *
     * @param \stdClass $sideline Instance record.
     * @param int $userid User.
     * @return string[]
     */
    private function names(\stdClass $sideline, int $userid): array {
        return array_values(array_map(static fn($cm) => $cm->name, (new scope_resolver())->resolve($sideline, $userid)));
    }

    public function test_section_scope_descends_into_nested_subsections(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['numsections' => 2], ['createsections' => true]);
        $student = $gen->create_and_enrol($course, 'student');
        $gen->create_module('page', ['course' => $course->id, 'section' => 1, 'name' => 'Top level']);
        $gen->create_module('page', ['course' => $course->id, 'section' => 2, 'name' => 'Other section']);
        $sub = $gen->create_module('subsection', ['course' => $course->id, 'section' => 1, 'name' => 'Sub']);
        $delegated = get_fast_modinfo($course)->get_cm($sub->cmid)->get_delegated_section_info();
        $gen->create_module('page', ['course' => $course->id, 'section' => $delegated->section, 'name' => 'Inside sub']);

        $instance = $gen->create_module('sideline', ['course' => $course->id, 'section' => 0, 'scopetype' => 'section',
            'scopesection' => 1, 'scopesubsections' => 1]);
        $sideline = $DB->get_record('sideline', ['id' => $instance->id]);
        $names = $this->names($sideline, (int) $student->id);
        $this->assertContains('Top level', $names);
        $this->assertContains('Inside sub', $names);
        $this->assertNotContains('Other section', $names);
        $this->assertNotContains('Sub', $names, 'The container itself is not an activity to observe');

        $DB->set_field('sideline', 'scopesubsections', 0, ['id' => $sideline->id]);
        $this->assertNotContains(
            'Inside sub',
            $this->names($DB->get_record('sideline', ['id' => $sideline->id]), (int) $student->id),
        );

        // A hidden subsection hides its content.
        $DB->set_field('sideline', 'scopesubsections', 1, ['id' => $sideline->id]);
        set_coursemodule_visible($sub->cmid, 0);
        rebuild_course_cache($course->id, true);
        $this->assertNotContains(
            'Inside sub',
            $this->names($DB->get_record('sideline', ['id' => $sideline->id]), (int) $student->id),
        );
    }

    public function test_course_union_needs_the_site_switch_teacher_rights_and_learner_enrolment(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $main = $gen->create_course();
        $other = $gen->create_course();
        $foreign = $gen->create_course();
        $gen->create_module('page', ['course' => $other->id, 'name' => 'From other course']);
        $gen->create_module('page', ['course' => $foreign->id, 'name' => 'From foreign course']);
        $teacher = $gen->create_and_enrol($main, 'editingteacher');
        $gen->enrol_user($teacher->id, $other->id, 'editingteacher');
        $student = $gen->create_and_enrol($main, 'student');
        $gen->enrol_user($student->id, $other->id, 'student');
        $gen->enrol_user($student->id, $foreign->id, 'student');
        $instance = $gen->create_module('sideline', ['course' => $main->id]);
        $sideline = $DB->get_record('sideline', ['id' => $instance->id]);

        // Off by default: nothing is stored.
        instance_config::save_sources($sideline, [$other->id], (int) $teacher->id);
        $this->assertSame([], instance_config::get_sources((int) $sideline->id));

        set_config('crosscourse', 1, 'mod_sideline');
        // The teacher has no rights in the foreign course: only the other course is kept.
        instance_config::save_sources($sideline, [$other->id, $foreign->id], (int) $teacher->id);
        $this->assertSame([(int) $other->id], instance_config::get_sources((int) $sideline->id));
        $names = $this->names($sideline, (int) $student->id);
        $this->assertContains('From other course', $names);
        $this->assertNotContains('From foreign course', $names);

        // Enrolment ends: the course disappears from the scope at once.
        $enrol = $DB->get_record('enrol', ['courseid' => $other->id, 'enrol' => 'manual']);
        $DB->set_field('user_enrolments', 'status', ENROL_USER_SUSPENDED, ['enrolid' => $enrol->id, 'userid' => $student->id]);
        $this->assertNotContains('From other course', $this->names($sideline, (int) $student->id));
    }

    public function test_cache_of_a_union_is_marked_dirty_by_events_in_the_source_course(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('crosscourse', 1, 'mod_sideline');
        $gen = $this->getDataGenerator();
        $main = $gen->create_course();
        $other = $gen->create_course();
        $instance = $gen->create_module('sideline', ['course' => $main->id]);
        $DB->insert_record('sideline_source', (object) ['sidelineid' => $instance->id, 'courseid' => $other->id]);
        $DB->insert_record('sideline_cache', (object) ['sidelineid' => $instance->id, 'userid' => 5, 'fingerprint' => 'f',
            'source' => 'template', 'commentary' => '{}', 'dirty' => 0, 'nexttransition' => 0, 'stamp' => 0,
            'timecreated' => 1, 'timemodified' => 1]);
        \mod_sideline\local\cache\commentary_cache::mark_user_dirty((int) $other->id, 5);
        $row = $DB->get_record('sideline_cache', ['sidelineid' => $instance->id]);
        $this->assertEquals(1, $row->dirty);
        $this->assertEquals(1, $row->stamp);
    }
}
