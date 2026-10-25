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

namespace mod_sideline\local\debug;

use mod_sideline\privacy\provider;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\writer;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/fake_text_generator.php');

/**
 * Administrator debug export, traces, audit log, retention and privacy completeness (P1-10).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_sideline\local\debug\debug_exporter
 * @covers \mod_sideline\local\debug\trace_recorder
 * @covers \mod_sideline\privacy\provider
 */
#[\PHPUnit\Framework\Attributes\CoversClass(debug_exporter::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(trace_recorder::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
final class debug_export_test extends \core_privacy\tests\provider_testcase {
    /** @var \stdClass Course. */
    private \stdClass $course;
    /** @var \stdClass Learner. */
    private \stdClass $student;
    /** @var \stdClass Instance. */
    private \stdClass $instance;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->getDataGenerator()->create_module('page', ['course' => $this->course->id, 'name' => 'Lesson']);
        $this->instance = $this->getDataGenerator()->create_module('sideline', ['course' => $this->course->id, 'dialogenabled' => 1,
            'sharingmode' => 'closed']);
    }

    /**
     * Generate commentary as the learner.
     */
    private function generate(): void {
        global $DB;
        $this->setUser($this->student);
        (new \mod_sideline\local\service\commentary_service(new \mod_sideline\local\ai\ai_gateway(
            new \mod_sideline\tests\fake_text_generator()
        )))->get(
            $DB->get_record('sideline', ['id' => $this->instance->id]),
            $this->course,
            $this->student,
            \context_module::instance($this->instance->cmid),
            time()
        );
    }

    public function test_only_site_administrators_may_export_and_a_purpose_is_required(): void {
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        try {
            debug_exporter::export(
                (int) $this->instance->cmid,
                (int) $this->student->id,
                'Support ticket 4711',
                0,
                time(),
                (int) $teacher->id
            );
            $this->fail('A teacher could export');
        } catch (\required_capability_exception $e) {
            $this->assertStringContainsString('nopermissions', $e->errorcode);
        }
        $this->expectException(\invalid_parameter_exception::class);
        debug_exporter::export((int) $this->instance->cmid, (int) $this->student->id, 'short', 0, time(), (int) get_admin()->id);
    }

    public function test_export_contains_inputs_decision_output_and_is_audited_without_writing(): void {
        global $DB;
        $before = $DB->count_records('sideline_cache');
        $export = debug_exporter::export(
            (int) $this->instance->cmid,
            (int) $this->student->id,
            'Support ticket 4711',
            0,
            time(),
            (int) get_admin()->id
        );
        $this->assertSame($before, $DB->count_records('sideline_cache'), 'The export computes without storing');
        $this->assertNotEmpty($export['inputs_and_decision']['states']);
        $this->assertNotEmpty($export['inputs_and_decision']['candidates']);
        $this->assertNotEmpty($export['inputs_and_decision']['selected']);
        $this->assertNotEmpty($export['output']['blocks']);
        $log = $DB->get_record('sideline_debuglog', ['userid' => $this->student->id]);
        $this->assertSame('Support ticket 4711', $log->purpose);
        $this->assertEquals(get_admin()->id, $log->adminid);
    }

    public function test_traces_only_while_tracing_is_on_and_they_expire(): void {
        global $DB;
        $this->generate();
        $this->assertSame(0, $DB->count_records('sideline_trace'));

        set_config('tracing', 1, 'mod_sideline');
        set_config('tracinguntil', time() + HOURSECS, 'mod_sideline');
        $DB->delete_records('sideline_cache');
        $this->generate();
        $this->assertSame(1, $DB->count_records('sideline_trace'));
        $export = debug_exporter::export(
            (int) $this->instance->cmid,
            (int) $this->student->id,
            'Data protection request',
            0,
            time() + 10,
            (int) get_admin()->id
        );
        $this->assertCount(1, $export['traces']);

        // Expired window: tracing stops by itself.
        set_config('tracinguntil', time() - 1, 'mod_sideline');
        $DB->delete_records('sideline_cache');
        $this->generate();
        $this->assertSame(1, $DB->count_records('sideline_trace'));

        // Retention.
        $DB->set_field('sideline_trace', 'timecreated', time() - 30 * DAYSECS);
        $DB->set_field('sideline_debuglog', 'timecreated', time() - 400 * DAYSECS);
        $DB->insert_record('sideline_answer', (object) ['questionid' => 1, 'sidelineid' => $this->instance->id,
            'userid' => $this->student->id, 'value' => '"old"', 'skipped' => 0, 'timecreated' => 1, 'timemodified' => 1]);
        set_config('answerretentiondays', 365, 'mod_sideline');
        (new \mod_sideline\task\purge_cache())->execute();
        $this->expectOutputRegex('/deleted/');
        $this->assertSame(0, $DB->count_records('sideline_trace'));
        $this->assertSame(0, $DB->count_records('sideline_debuglog'));
        $this->assertSame(0, $DB->count_records('sideline_answer'));
        $this->assertEquals(0, get_config('mod_sideline', 'tracing'));
    }

    public function test_privacy_covers_every_new_kind_of_data(): void {
        global $DB;
        $names = array_map(
            static fn($i) => $i->get_name(),
            provider::get_metadata(new collection('mod_sideline'))->get_collection(),
        );
        foreach (
            ['sideline_pref', 'sideline_answer', 'sideline_rulehit', 'sideline_publish', 'sideline_trace',
                'sideline_debuglog'] as $table
        ) {
            $this->assertContains($table, $names);
        }
        $sideline = $DB->get_record('sideline', ['id' => $this->instance->id]);
        $qid = $this->getDataGenerator()->get_plugin_generator('mod_sideline')->create_question(['sidelineid' => $sideline->id,
            'qtype' => 'text', 'config' => ['maxlength' => 100]]);
        \mod_sideline\local\dialog\dialog_manager::answer($sideline, (int) $this->student->id, $qid, 'Mine only', false, time());
        \mod_sideline\local\group\sharing_manager::record($sideline, (int) $this->student->id, true, true, 1, time());
        $DB->insert_record('sideline_pref', (object) ['sidelineid' => $sideline->id, 'userid' => $this->student->id,
            'persona' => 'reporter', 'timemodified' => time()]);
        debug_exporter::export(
            (int) $this->instance->cmid,
            (int) $this->student->id,
            'Data protection request',
            0,
            time(),
            (int) get_admin()->id
        );

        $context = \context_module::instance($this->instance->cmid);
        $this->assertContains(
            (int) $context->id,
            array_map('intval', provider::get_contexts_for_userid((int) $this->student->id)->get_contextids()),
        );
        $this->assertContains(
            (int) $context->id,
            array_map('intval', provider::get_contexts_for_userid((int) get_admin()->id)->get_contextids()),
        );

        $this->export_context_data_for_user((int) $this->student->id, $context, 'mod_sideline');
        $writer = writer::with_context($context);
        $answers = $writer->get_data([get_string('privacy:path:sideline_answer', 'mod_sideline')]);
        $this->assertStringContainsString('Mine only', json_encode($answers));
        $this->assertNotEmpty($writer->get_data([get_string('privacy:path:sideline_publish', 'mod_sideline')]));
        $this->assertNotEmpty($writer->get_data([get_string('privacy:path:sideline_pref', 'mod_sideline')]));

        provider::delete_data_for_user(new approved_contextlist($this->student, 'mod_sideline', [$context->id]));
        foreach (['sideline_answer', 'sideline_publish', 'sideline_pref', 'sideline_debuglog', 'sideline_consent_log'] as $table) {
            $this->assertSame(0, $DB->count_records($table, ['userid' => $this->student->id]), $table);
        }
        provider::delete_data_for_user(new approved_contextlist(get_admin(), 'mod_sideline', [$context->id]));
        $this->assertSame(0, $DB->count_records('sideline_debuglog', ['adminid' => get_admin()->id]));
    }
}
