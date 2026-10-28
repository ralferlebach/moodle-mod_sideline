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

namespace mod_sideline\backup;

use mod_sideline\local\instance_config;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
// The plan builders load the moodle2 base classes that the plugin's task and step classes extend.
require_once($CFG->dirroot . '/backup/moodle2/backup_plan_builder.class.php');
require_once($CFG->dirroot . '/backup/moodle2/restore_plan_builder.class.php');
require_once($CFG->dirroot . '/mod/sideline/backup/moodle2/backup_sideline_activity_task.class.php');
require_once($CFG->dirroot . '/mod/sideline/backup/moodle2/restore_sideline_activity_task.class.php');

/**
 * Backup and restore of the configuration, the scope and the absence of personal data (SLC-020-P1-12).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \backup_sideline_activity_task
 * @covers \backup_sideline_activity_structure_step
 * @covers \restore_sideline_activity_task
 * @covers \restore_sideline_activity_structure_step
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\backup_sideline_activity_task::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\backup_sideline_activity_structure_step::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\restore_sideline_activity_task::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\restore_sideline_activity_structure_step::class)]
final class backup_restore_test extends \advanced_testcase {
    /**
     * Back a course up and restore it into a new course.
     *
     * @param \stdClass $course Source course.
     * @return \stdClass The new course.
     */
    private function duplicate(\stdClass $course): \stdClass {
        global $CFG;
        $this->setAdminUser();
        $admin = get_admin();

        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $admin->id
        );
        $bc->execute_plan();
        $file = $bc->get_results()['backup_destination'];
        $folder = 'sideline-restore-' . $course->id;
        $path = $CFG->dataroot . '/temp/backup/' . $folder;
        $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), $path);
        $bc->destroy();

        $new = $this->getDataGenerator()->create_course();
        $rc = new \restore_controller(
            $folder,
            $new->id,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $admin->id,
            \backup::TARGET_EXISTING_DELETING
        );
        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();
        return $new;
    }

    public function test_configuration_and_scope_survive_a_course_copy_and_no_personal_data_does(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['enablecompletion' => 1]);
        $student = $gen->create_and_enrol($course, 'student');
        // The Sideline activity is created before its targets on purpose: they are restored after it.
        $sideline = $gen->create_module('sideline', [
            'course' => $course->id, 'persona' => 'reporter', 'consentrequired' => 1, 'aienabled' => 1,
            'scopetype' => instance_config::SCOPE_ACTIVITIES,
        ]);
        $p1 = $gen->create_module('page', ['course' => $course->id, 'name' => 'Target one']);
        $gen->create_module('page', ['course' => $course->id, 'name' => 'Not observed']);
        instance_config::save_scope_activities((int) $sideline->id, [(int) $p1->cmid]);
        $DB->insert_record('sideline_consent', (object) [
            'sidelineid' => $sideline->id, 'userid' => $student->id, 'status' => 'accepted',
            'infoversion' => 1, 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $DB->insert_record('sideline_cache', (object) [
            'sidelineid' => $sideline->id, 'userid' => $student->id, 'fingerprint' => 'x', 'source' => 'template',
            'commentary' => '{}', 'dirty' => 0, 'nexttransition' => 0, 'timecreated' => time(), 'timemodified' => time(),
        ]);

        $new = $this->duplicate($course);

        $copy = $DB->get_record('sideline', ['course' => $new->id], '*', MUST_EXIST);
        $this->assertSame('reporter', $copy->persona);
        $this->assertSame(instance_config::SCOPE_ACTIVITIES, $copy->scopetype);
        $this->assertEquals(1, $copy->consentrequired);

        // Scope points at the copy of "Target one" in the new course, not at the old course.
        $pages = $DB->get_records('page', ['course' => $new->id], '', 'id, name');
        $this->assertCount(2, $pages);
        $targetcmid = null;
        foreach ($pages as $page) {
            if ($page->name === 'Target one') {
                $targetcmid = (int) get_coursemodule_from_instance('page', $page->id)->id;
            }
        }
        $this->assertSame([$targetcmid], array_map('intval', instance_config::get_scope_activities((int) $copy->id)));

        // No consent, cache or log rows of people are carried over.
        $this->assertSame(0, $DB->count_records('sideline_consent', ['sidelineid' => $copy->id]));
        $this->assertSame(0, $DB->count_records('sideline_cache', ['sidelineid' => $copy->id]));
        $this->assertSame(0, $DB->count_records('sideline_consent_log', ['sidelineid' => $copy->id]));
    }
}
