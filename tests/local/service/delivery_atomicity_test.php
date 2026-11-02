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

use mod_sideline\local\ai\ai_gateway;
use mod_sideline\local\cache\commentary_cache;
use mod_sideline\local\consent\consent_manager;
use mod_sideline\tests\fake_text_generator;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/sideline/tests/fixtures/fake_text_generator.php');
require_once($CFG->dirroot . '/mod/sideline/tests/fixtures/commentary_course.php');

#[\PHPUnit\Framework\Attributes\CoversClass(commentary_service::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(consent_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(commentary_cache::class)]
/**
 * Withdrawal, invalidation and delivery are atomic (issue #3): deterministic interleavings.
 *
 * The interleavings are produced inside the AI call: the fake provider runs the concurrent action
 * (a withdrawal, an invalidation, a stop of sharing) while the generation is in flight. Real
 * parallel processes are covered by tools/race/run.php in the CI.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_sideline\local\service\commentary_service
 * @covers \mod_sideline\local\consent\consent_manager
 * @covers \mod_sideline\local\cache\commentary_cache
 */
final class delivery_atomicity_test extends \advanced_testcase {
    use \mod_sideline\tests\commentary_course;

    /**
     * A coach with AI on and a learner with consent.
     *
     * @param array $options Instance options.
     * @return array [instance, context]
     */
    private function setup_coach(array $options = []): array {
        $this->create_commentary_course();
        set_config('aienabled', 1, 'mod_sideline');
        [$instance, , $context] = $this->sideline(['aienabled' => 1, 'consentrequired' => 1] + $options);
        consent_manager::record_decision($instance, (int) $this->student->id, true, $context->id, time());
        return [$instance, $context];
    }

    /**
     * Ask the service as the learner, with an injectable lock factory.
     *
     * @param \stdClass $instance Instance.
     * @param \context_module $context Context.
     * @param fake_text_generator $fake Provider double.
     * @param \core\lock\lock_factory|null $locks Lock factory.
     * @return array [result, service]
     */
    private function ask(\stdClass $instance, \context_module $context, fake_text_generator $fake, $locks = null): array {
        global $DB;
        $this->setUser($this->student);
        $service = new commentary_service(new ai_gateway($fake), $locks);
        $record = $DB->get_record('sideline', ['id' => $instance->id]);
        return [$service->get($record, $this->course, $this->student, $context, time()), $service];
    }

    public function test_a_withdrawal_during_generation_delivers_and_stores_nothing(): void {
        global $DB;
        [$instance, $context] = $this->setup_coach();
        $fake = new fake_text_generator();
        $echo = fake_text_generator::echoing_tokens();
        $userid = (int) $this->student->id;
        $fake->responder = function (string $prompt) use ($echo, $instance, $context, $userid) {
            consent_manager::record_decision($instance, $userid, false, $context->id, time());
            return $echo($prompt);
        };
        [$result] = $this->ask($instance, $context, $fake);
        $this->assertSame(commentary_service::STATUS_CONSENT_REQUIRED, $result['status']);
        $this->assertNull($result['commentary']);
        $this->assertFalse($DB->record_exists('sideline_cache', ['sidelineid' => $instance->id, 'userid' => $userid]));
    }

    public function test_a_withdrawal_whose_cleanup_never_ran_is_still_caught_by_the_final_gate(): void {
        global $DB;
        [$instance, $context] = $this->setup_coach();
        $fake = new fake_text_generator();
        $echo = fake_text_generator::echoing_tokens();
        $userid = (int) $this->student->id;
        // Only the status changes (as if the withdrawing process died before it deleted anything).
        $fake->responder = function (string $prompt) use ($echo, $instance, $userid) {
            global $DB;
            $DB->set_field('sideline_consent', 'status', consent_manager::WITHDRAWN, ['sidelineid' => $instance->id,
                'userid' => $userid]);
            return $echo($prompt);
        };
        [$result] = $this->ask($instance, $context, $fake);
        $this->assertSame(commentary_service::STATUS_CONSENT_REQUIRED, $result['status']);
        $this->assertFalse($DB->record_exists('sideline_cache', ['sidelineid' => $instance->id, 'userid' => $userid]));
    }

    public function test_an_invalidation_during_generation_discards_the_text_and_computes_it_again(): void {
        global $DB;
        [$instance, $context] = $this->setup_coach();
        $this->ask($instance, $context, new fake_text_generator());
        $fake = new fake_text_generator();
        $echo = fake_text_generator::echoing_tokens();
        $courseid = (int) $this->course->id;
        $userid = (int) $this->student->id;
        $DB->set_field('sideline_cache', 'dirty', 1, ['sidelineid' => $instance->id]);
        $first = true;
        $fake->responder = function (string $prompt) use ($echo, $courseid, $userid, &$first) {
            if ($first) {
                $first = false;
                commentary_cache::mark_user_dirty($courseid, $userid);
            }
            return $echo($prompt);
        };
        [$result, $service] = $this->ask($instance, $context, $fake);
        $this->assertSame(2, $service->attempts, 'The stale text was discarded and computed again');
        $this->assertSame(2, $fake->calls);
        $this->assertSame('ai', $result['commentary']['source']);
        $dirty = $DB->get_field('sideline_cache', 'dirty', ['sidelineid' => $instance->id]);
        $this->assertEquals(0, $dirty, 'The fresh text is stored');
    }

    public function test_repeated_invalidations_yield_only_the_generic_text(): void {
        global $DB;
        [$instance, $context] = $this->setup_coach();
        $this->ask($instance, $context, new fake_text_generator());
        $DB->set_field('sideline_cache', 'dirty', 1, ['sidelineid' => $instance->id]);
        $fake = new fake_text_generator();
        $echo = fake_text_generator::echoing_tokens();
        $courseid = (int) $this->course->id;
        $userid = (int) $this->student->id;
        $fake->responder = function (string $prompt) use ($echo, $courseid, $userid) {
            commentary_cache::mark_user_dirty($courseid, $userid);
            return $echo($prompt);
        };
        [$result, $service] = $this->ask($instance, $context, $fake);
        $this->assertSame(commentary_service::MAX_ATTEMPTS, $service->attempts);
        $this->assertSame('generic', $result['commentary']['source'], 'No text computed before the invalidation is delivered');
        $this->assertSame('generic', $result['commentary']['blocks'][0]['key']);
        $this->assertNull($result['question']);
        $dirty = $DB->get_field('sideline_cache', 'dirty', ['sidelineid' => $instance->id]);
        $this->assertEquals(1, $dirty, 'Nothing stored as fresh');
    }

    public function test_a_lock_timeout_yields_the_generic_text_without_ai_and_without_storing(): void {
        global $DB;
        [$instance, $context] = $this->setup_coach();
        $fake = new fake_text_generator();
        $fake->responder = fake_text_generator::echoing_tokens();
        $locks = $this->createMock(\core\lock\lock_factory::class);
        $locks->method('get_lock')->willReturn(false);
        [$result] = $this->ask($instance, $context, $fake, $locks);
        $this->assertSame('generic', $result['commentary']['source']);
        $this->assertSame(0, $fake->calls);
        $this->assertFalse($DB->record_exists('sideline_cache', ['sidelineid' => $instance->id]));
    }

    public function test_a_lock_timeout_after_a_withdrawal_yields_no_output_at_all(): void {
        global $DB;
        [$instance, $context] = $this->setup_coach();
        $locks = $this->createMock(\core\lock\lock_factory::class);
        $userid = (int) $this->student->id;
        $locks->method('get_lock')->willReturnCallback(function () use ($instance, $userid) {
            global $DB;
            $DB->set_field('sideline_consent', 'status', consent_manager::WITHDRAWN, ['sidelineid' => $instance->id,
                'userid' => $userid]);
            return false;
        });
        [$result] = $this->ask($instance, $context, new fake_text_generator(), $locks);
        $this->assertSame(commentary_service::STATUS_CONSENT_REQUIRED, $result['status']);
        $this->assertNull($result['commentary']);
    }

    public function test_a_cache_hit_passes_the_final_gate(): void {
        global $DB;
        [$instance, $context] = $this->setup_coach();
        $this->ask($instance, $context, new fake_text_generator());
        $this->assertTrue($DB->record_exists('sideline_cache', ['sidelineid' => $instance->id]));
        // The withdrawal is written, the cached row is left behind on purpose.
        $DB->set_field('sideline_consent', 'status', consent_manager::WITHDRAWN, ['sidelineid' => $instance->id]);
        [$result] = $this->ask($instance, $context, new fake_text_generator());
        $this->assertSame(commentary_service::STATUS_CONSENT_REQUIRED, $result['status']);
    }

    public function test_the_debug_simulation_respects_a_withdrawal(): void {
        global $DB;
        [$instance, $context] = $this->setup_coach();
        consent_manager::record_decision($instance, (int) $this->student->id, false, $context->id, time());
        $record = $DB->get_record('sideline', ['id' => $instance->id]);
        $result = (new commentary_service(new ai_gateway(new fake_text_generator())))
            ->get($record, $this->course, $this->student, $context, time(), true);
        $this->assertSame(commentary_service::STATUS_CONSENT_REQUIRED, $result['status']);
        $this->assertArrayNotHasKey('debug', $result);
    }

    public function test_a_withdrawal_whose_lock_times_out_still_removes_the_texts(): void {
        global $DB;
        [$instance, $context] = $this->setup_coach();
        $this->ask($instance, $context, new fake_text_generator());
        $this->assertTrue($DB->record_exists('sideline_cache', ['sidelineid' => $instance->id]));
        // The generation lock is held by somebody else for longer than the withdrawal waits.
        $locks = $this->createMock(\core\lock\lock_factory::class);
        $locks->method('get_lock')->willReturn(false);
        consent_manager::record_decision($instance, (int) $this->student->id, false, $context->id, time(), $locks);
        $this->assertFalse($DB->record_exists('sideline_cache', ['sidelineid' => $instance->id]));
        $this->assertSame(consent_manager::WITHDRAWN, consent_manager::status($instance, (int) $this->student->id));
    }
}
