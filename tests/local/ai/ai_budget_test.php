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

namespace mod_sideline\local\ai;

use mod_sideline\local\model\recommendation;
use mod_sideline\tests\fake_text_generator;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/sideline/tests/fixtures/fake_text_generator.php');

#[\PHPUnit\Framework\Attributes\CoversClass(ai_usage::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(ai_gateway::class)]
/**
 * Site-wide AI cost caps under concurrency, worst-case tokens and open reservations (issue #8).
 *
 * Real parallel processes (20 users of different instances racing for the last call, on PostgreSQL
 * and MariaDB) are run by tools/race/run.php in the CI; these tests cover the decisions.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_sideline\local\ai\ai_usage
 * @covers \mod_sideline\local\ai\ai_gateway
 */
final class ai_budget_test extends \advanced_testcase {
    /** @var int Fixed time. */
    private const NOW = 1800000000;

    public function test_a_reservation_counts_the_worst_case_until_it_completes(): void {
        global $DB;
        $this->resetAfterTest();
        $prompt = str_repeat('x', 400);
        $id = ai_usage::reserve(1, ai_usage::GENERATE, $prompt, self::NOW);
        $row = $DB->get_record('sideline_aiusage', ['id' => $id]);
        $this->assertEquals(100 + ai_usage::MAX_RESPONSE_TOKENS, $row->tokens);
        $this->assertEquals(0, $row->timecompleted, 'Open while the call runs');
        $this->assertSame(1, ai_usage::summary(self::NOW)['open']);

        ai_usage::complete($id, true, 150, $prompt, 'answer', self::NOW + 2);
        $row = $DB->get_record('sideline_aiusage', ['id' => $id]);
        $this->assertEquals(150, $row->tokens);
        $this->assertEquals(self::NOW + 2, $row->timecompleted);
        $this->assertSame(0, ai_usage::summary(self::NOW)['open']);
    }

    public function test_the_token_cap_refuses_a_call_whose_worst_case_does_not_fit(): void {
        $this->resetAfterTest();
        set_config('aidailytokencap', ai_usage::MAX_RESPONSE_TOKENS + 200, 'mod_sideline');
        $first = ai_usage::reserve(1, ai_usage::GENERATE, str_repeat('x', 400), self::NOW);
        $this->assertNotNull($first);
        ai_usage::complete($first, true, 150, '', '', self::NOW);
        // 150 used, a further call could take 100 + 1024 tokens: more than the 1074 left.
        $this->assertNull(ai_usage::reserve(2, ai_usage::GENERATE, str_repeat('x', 400), self::NOW));
    }

    public function test_the_hourly_cap_holds_for_many_users_and_instances(): void {
        $this->resetAfterTest();
        set_config('aihourlycap', 5, 'mod_sideline');
        $granted = 0;
        for ($i = 1; $i <= 20; $i++) {
            $granted += ai_usage::reserve($i, ai_usage::GENERATE, 'prompt', self::NOW) !== null ? 1 : 0;
        }
        $this->assertSame(5, $granted);
    }

    public function test_an_open_reservation_of_an_aborted_call_keeps_counting(): void {
        $this->resetAfterTest();
        set_config('aihourlycap', 1, 'mod_sideline');
        $this->assertNotNull(ai_usage::reserve(1, ai_usage::GENERATE, 'prompt', self::NOW));
        // The process died: complete() never runs. The reservation still counts.
        $this->assertNull(ai_usage::reserve(2, ai_usage::GENERATE, 'prompt', self::NOW + 60));
        $this->assertNotNull(ai_usage::reserve(2, ai_usage::GENERATE, 'prompt', self::NOW + HOURSECS + 1));
    }

    public function test_without_the_site_wide_lock_no_call_is_made(): void {
        global $DB;
        $this->resetAfterTest();
        $locks = $this->createMock(\core\lock\lock_factory::class);
        $locks->method('get_lock')->willReturn(false);
        $this->assertNull(ai_usage::reserve(1, ai_usage::GENERATE, 'prompt', self::NOW, $locks));
        $this->assertSame(0, $DB->count_records('sideline_aiusage'));
    }

    public function test_an_administrator_lowering_the_cap_applies_to_the_next_reservation(): void {
        $this->resetAfterTest();
        set_config('aihourlycap', 10, 'mod_sideline');
        $this->assertNotNull(ai_usage::reserve(1, ai_usage::GENERATE, 'prompt', self::NOW));
        $this->assertNotNull(ai_usage::reserve(1, ai_usage::GENERATE, 'prompt', self::NOW));
        set_config('aihourlycap', 2, 'mod_sideline');
        $this->assertNull(ai_usage::reserve(1, ai_usage::GENERATE, 'prompt', self::NOW));
    }

    public function test_a_provider_outage_completes_the_reservation_as_failed(): void {
        global $DB;
        $this->resetAfterTest();
        $fake = new fake_text_generator();
        $fake->responder = static function (): string {
            throw new \moodle_exception('error');
        };
        $gateway = new ai_gateway($fake);
        $rec = new recommendation('smalltalk', recommendation::CAT_SMALLTALK, 100);
        $this->assertNull($gateway->phrase(
            [$rec],
            (object) ['id' => 2, 'firstname' => 'A'],
            \context_system::instance(),
            'mentor',
            'en',
            self::NOW,
            1
        ));
        $row = $DB->get_record('sideline_aiusage', []);
        $this->assertEquals(0, $row->success);
        $this->assertGreaterThan(0, (int) $row->timecompleted, 'Completed, not left open');
        $this->assertLessThan(ai_usage::MAX_RESPONSE_TOKENS, (int) $row->tokens, 'Counted with the prompt only');
    }
}
