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

namespace mod_sideline;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/sideline/lib.php');

/**
 * Module callbacks in lib.php: features, instance life cycle, view event, tracing switch, the scheduled task.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers ::sideline_supports
 * @covers ::sideline_add_instance
 * @covers ::sideline_update_instance
 * @covers ::sideline_delete_instance
 * @covers ::sideline_view
 * @covers ::sideline_save_relations
 * @covers ::mod_sideline_tracing_updated
 * @covers \mod_sideline\event\course_module_viewed
 * @covers \mod_sideline\task\purge_cache
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('sideline_supports')]
#[\PHPUnit\Framework\Attributes\CoversFunction('sideline_add_instance')]
#[\PHPUnit\Framework\Attributes\CoversFunction('sideline_update_instance')]
#[\PHPUnit\Framework\Attributes\CoversFunction('sideline_delete_instance')]
#[\PHPUnit\Framework\Attributes\CoversFunction('sideline_view')]
#[\PHPUnit\Framework\Attributes\CoversFunction('sideline_save_relations')]
#[\PHPUnit\Framework\Attributes\CoversFunction('mod_sideline_tracing_updated')]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_sideline\event\course_module_viewed::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_sideline\task\purge_cache::class)]
final class lib_test extends \advanced_testcase {
    public function test_supported_features(): void {
        $this->assertTrue(sideline_supports(FEATURE_MOD_INTRO));
        $this->assertTrue(sideline_supports(FEATURE_BACKUP_MOODLE2));
        $this->assertFalse((bool) sideline_supports(FEATURE_GRADE_HAS_GRADE));
        $this->assertNull(sideline_supports('some_unknown_feature'));
    }

    public function test_instance_life_cycle_keeps_relations_and_removes_everything(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $instance = $this->getDataGenerator()->create_module('sideline', ['course' => $course->id,
            'scopetype' => 'activities', 'scopecmids' => [$page->cmid]]);
        $this->assertSame([(int) $page->cmid], \mod_sideline\local\instance_config::get_scope_activities((int) $instance->id));

        $record = $DB->get_record('sideline', ['id' => $instance->id]);
        $record->instance = $record->id;
        $record->coursemodule = $instance->cmid;
        $record->scopecmids = [];
        $record->name = 'Renamed';
        $this->assertTrue(sideline_update_instance($record));
        $this->assertSame('Renamed', $DB->get_field('sideline', 'name', ['id' => $instance->id]));
        $this->assertSame([], \mod_sideline\local\instance_config::get_scope_activities((int) $instance->id));

        $DB->insert_record('sideline_cache', (object) ['sidelineid' => $instance->id, 'userid' => 2, 'fingerprint' => 'x',
            'source' => 'template', 'commentary' => '{}', 'dirty' => 0, 'nexttransition' => 0, 'timecreated' => 1,
            'timemodified' => 1]);
        $this->assertTrue(sideline_delete_instance((int) $instance->id));
        $this->assertFalse($DB->record_exists('sideline', ['id' => $instance->id]));
        $this->assertSame(0, $DB->count_records('sideline_cache', ['sidelineid' => $instance->id]));
        $this->assertFalse(sideline_delete_instance((int) $instance->id), 'Deleting twice reports false');
    }

    public function test_view_triggers_the_event_and_marks_the_activity_viewed(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $instance = $this->getDataGenerator()->create_module('sideline', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('sideline', $instance->id);
        $this->setUser($user);
        $sink = $this->redirectEvents();
        sideline_view($instance, $course, $cm, \context_module::instance($cm->id));
        $events = array_values(array_filter($sink->get_events(), static fn($e) => $e instanceof event\course_module_viewed));
        $sink->close();
        $this->assertCount(1, $events);
        $this->assertSame((int) $cm->id, (int) $events[0]->contextinstanceid);
        $this->assertSame('r', $events[0]->crud);
    }

    public function test_tracing_switch_sets_an_end_time_and_clears_it(): void {
        $this->resetAfterTest();
        set_config('tracing', 1, 'mod_sideline');
        set_config('tracingduration', HOURSECS, 'mod_sideline');
        mod_sideline_tracing_updated('s_mod_sideline_tracing');
        $until = (int) get_config('mod_sideline', 'tracinguntil');
        $this->assertGreaterThan(time(), $until);
        $this->assertLessThanOrEqual(time() + HOURSECS, $until);
        set_config('tracing', 0, 'mod_sideline');
        mod_sideline_tracing_updated('s_mod_sideline_tracing');
        $this->assertSame(0, (int) get_config('mod_sideline', 'tracinguntil'));
    }

    public function test_scheduled_task_applies_every_retention_period(): void {
        global $DB;
        $this->resetAfterTest();
        $now = time();
        $old = $now - 400 * DAYSECS;
        $insert = static function (string $table, array $row) use ($DB): void {
            $DB->insert_record($table, (object) $row);
        };
        foreach ([$old, $now] as $time) {
            $insert('sideline_cache', ['sidelineid' => 1, 'userid' => $time === $old ? 2 : 3, 'fingerprint' => 'x',
                'source' => 'template', 'commentary' => '{}', 'dirty' => 0, 'nexttransition' => 0, 'timecreated' => $time,
                'timemodified' => $time]);
            $insert('sideline_trace', ['sidelineid' => 1, 'userid' => 2, 'trace' => '{}', 'timecreated' => $time]);
            $insert('sideline_debuglog', ['adminid' => 2, 'sidelineid' => 1, 'userid' => 2, 'purpose' => 'Support case',
                'timefrom' => 0, 'timeto' => 0, 'timecreated' => $time]);
            $insert('sideline_rulehit', ['sidelineid' => 1, 'userid' => 2, 'rulekey' => 'custom_x', 'timeshown' => $time]);
            $insert('sideline_answer', ['questionid' => $time === $old ? 1 : 2, 'sidelineid' => 1, 'userid' => 2, 'value' => '1',
                'skipped' => 0, 'timecreated' => $time, 'timemodified' => $time]);
            $insert('sideline_consent_log', ['sidelineid' => 1, 'userid' => 2, 'kind' => 'analysis', 'status' => 'accepted',
                'infoversion' => 1, 'contextid' => 1, 'timecreated' => $time]);
            $insert('sideline_aiusage', ['sidelineid' => 1, 'purpose' => 'generate', 'success' => 1, 'tokens' => 5,
                'timecreated' => $time]);
        }
        set_config('consentlogretentiondays', 30, 'mod_sideline');
        set_config('tracing', 1, 'mod_sideline');
        set_config('tracinguntil', $now - 1, 'mod_sideline');

        ob_start();
        (new task\purge_cache())->execute();
        $output = ob_get_clean();

        $this->assertStringContainsString('deleted 1 cached', $output);
        foreach (
            ['sideline_cache', 'sideline_trace', 'sideline_debuglog', 'sideline_rulehit', 'sideline_answer',
                'sideline_consent_log', 'sideline_aiusage'] as $table
        ) {
            $this->assertSame(1, $DB->count_records($table), "Only the recent row stays in $table");
        }
        $this->assertSame('0', get_config('mod_sideline', 'tracing'), 'Expired tracing is switched off');
        $this->assertNotEmpty((new task\purge_cache())->get_name());
    }
}
