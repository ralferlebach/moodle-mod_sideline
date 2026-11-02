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

use mod_sideline\local\ai\ai_gateway;
use mod_sideline\local\service\commentary_service;
use mod_sideline\tests\fake_text_generator;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/sideline/tests/fixtures/fake_text_generator.php');
require_once($CFG->dirroot . '/mod/sideline/tests/fixtures/commentary_course.php');

#[\PHPUnit\Framework\Attributes\CoversClass(debug_exporter::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(trace_recorder::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_sideline\local\service\commentary_service::class)]
/**
 * The debug export shows what was actually delivered and marks the difference to the live simulation
 * (issue #14). Traces hold the AI exchange with tokens only, never a real value.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_sideline\local\debug\debug_exporter
 * @covers \mod_sideline\local\debug\trace_recorder
 * @covers \mod_sideline\local\service\commentary_service
 */
final class trace_history_test extends \advanced_testcase {
    use \mod_sideline\tests\commentary_course;

    public function test_the_delivered_ai_text_is_kept_and_marked_as_different_from_the_simulation(): void {
        global $DB;
        $this->create_commentary_course([], ['firstname' => 'Maria']);
        set_config('aienabled', 1, 'mod_sideline');
        set_config('tracing', 1, 'mod_sideline');
        set_config('tracinguntil', time() + HOURSECS, 'mod_sideline');
        [$instance, $cm, $context] = $this->sideline(['aienabled' => 1]);
        $fake = new fake_text_generator();
        $fake->responder = fake_text_generator::echoing_tokens();
        $delivered = $this->get($instance, $context, $fake);
        $this->assertSame('ai', $delivered['commentary']['source']);

        $trace = json_decode($DB->get_field('sideline_trace', 'trace', []), true);
        $this->assertSame($delivered['commentary']['blocks'][0]['text'], $trace['output'][0]['text']);
        $this->assertSame(hash('sha256', $fake->prompts[0]), $trace['ai']['prompthash']);
        $this->assertSame(array_column($delivered['commentary']['blocks'], 'key'), array_keys($trace['ai']['response']));
        $this->assertStringNotContainsString('Alpha unit', json_encode($trace['ai']));
        $this->assertStringNotContainsString('Maria', json_encode($trace['ai']));

        $this->setAdminUser();
        $export = debug_exporter::export(
            (int) $cm->id,
            (int) $this->student->id,
            'Support request 123',
            0,
            time() + 10,
            (int) get_admin()->id
        );
        $this->assertSame('simulation', $export['output_kind']);
        $this->assertTrue($export['traces'][0]['output_recorded']);
        $this->assertTrue($export['traces'][0]['differs_from_simulation'], 'AI text then, template text in the simulation now');
        $this->assertSame(1, $DB->count_records('sideline_debuglog', ['sidelineid' => $instance->id]), 'Every access is audited');
    }
}
