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

use mod_sideline\local\model\activity_state;
use mod_sideline\local\model\recommendation;

/**
 * Property tests for the rules (blueprint L): invariants over many random state combinations.
 *
 * The generator is seeded, so every run checks the same 2000 cases and a failure is reproducible
 * from the seed and case number in the message. The invariants are what the specification promises
 * whatever the combination:
 *
 * - never a negative statement from unknown data (P-01): no "not passed" without a known fail,
 *   no deadline message without a known open completion, no lock message without a known lock;
 * - never an action to an activity that is invisible or not available (P-04);
 * - at most one main message plus MAX_SUPPLEMENTS, never empty, safety first (P-06);
 * - deterministic: the same input gives the same output, independent of the order of the input;
 * - a retry is promised only when an attempt is known to be left, "no attempt left" only when known;
 * - the next transition is in the future or null.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_sideline\local\service\rule_evaluator
 * @covers \mod_sideline\local\model\activity_state
 * @covers \mod_sideline\local\model\recommendation
 * @covers \mod_sideline\local\service\evaluation_context
 */
#[\PHPUnit\Framework\Attributes\CoversClass(rule_evaluator::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(activity_state::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(recommendation::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(evaluation_context::class)]
final class rule_property_test extends \advanced_testcase {
    /** @var int Fixed time. */
    private const NOW = 1800000000;

    /** @var int Seed of the generator. */
    private const SEED = 20261028;

    /** @var int Number of cases. */
    private const CASES = 2000;

    /**
     * A random value from a list.
     *
     * @param array $values Values.
     * @return mixed
     */
    private static function pick(array $values): mixed {
        return $values[mt_rand(0, count($values) - 1)];
    }

    /**
     * A random activity state.
     *
     * @param int $cmid Course module id.
     * @return activity_state
     */
    private static function random_state(int $cmid): activity_state {
        $tri = [activity_state::YES, activity_state::NO, activity_state::UNKNOWN];
        $time = static fn() => self::pick([null, self::NOW - 20 * DAYSECS, self::NOW - HOURSECS, self::NOW + HOURSECS,
            self::NOW + 2 * DAYSECS, self::NOW + 30 * DAYSECS]);
        $allowed = self::pick([null, 0, 1, 3]);
        return new activity_state(
            cmid: $cmid,
            label: 'Activity ' . $cmid,
            visible: (bool) mt_rand(0, 4),
            available: self::pick($tri),
            completion: self::pick($tri),
            pass: self::pick($tri),
            grade: self::pick([null, 0.0, 45.0, 80.0]),
            deadline: $time(),
            opensat: $time(),
            lastactivity: self::pick([null, self::NOW - 30 * DAYSECS, self::NOW - DAYSECS]),
            lastused: self::pick([null, self::NOW - 30 * DAYSECS, self::NOW - HOURSECS]),
            attemptsused: $allowed === null ? null : mt_rand(0, 3),
            attemptsallowed: $allowed,
            submitted: self::pick($tri),
            lockreason: self::pick([null, activity_state::LOCK_DATE, activity_state::LOCK_COMPLETION,
                activity_state::LOCK_GRADE, activity_state::LOCK_GROUP, activity_state::LOCK_OTHER]),
        );
    }

    /**
     * A random context: effects of answers and course completion.
     *
     * @return evaluation_context
     */
    private static function random_context(): evaluation_context {
        $effects = array_values(array_filter(['focus_deadlines', 'small_steps', 'pause_ok'], static fn() => mt_rand(0, 3) === 0));
        return new evaluation_context(
            effects: $effects,
            coursecomplete: self::pick([activity_state::YES, activity_state::NO, activity_state::UNKNOWN]),
        );
    }

    public function test_invariants_hold_for_random_states(): void {
        mt_srand(self::SEED);
        $evaluator = new rule_evaluator();
        for ($case = 1; $case <= self::CASES; $case++) {
            $states = [];
            for ($i = 1, $n = mt_rand(0, 6); $i <= $n; $i++) {
                $states[100 + $i] = self::random_state(100 + $i);
            }
            $ctx = self::random_context();
            $where = 'seed ' . self::SEED . ', case ' . $case;

            $candidates = $evaluator->candidates($states, self::NOW, $ctx);
            $selected = $evaluator->select($candidates);

            $this->assertNotEmpty($selected, $where);
            $this->assertLessThanOrEqual(1 + rule_evaluator::MAX_SUPPLEMENTS, count($selected), $where);
            $categories = array_map(static fn(recommendation $r) => $r->category, $selected);
            $this->assertSame(min($categories), $categories[0], "Main message has the highest precedence ($where)");

            foreach ($candidates as $rec) {
                $state = $rec->targetcmid ? ($states[$rec->targetcmid] ?? null) : null;
                if ($rec->actiontype === recommendation::ACTION_OPEN) {
                    $this->assertNotNull($state, "Action without a target ($where)");
                    $this->assertTrue($state->visible, "Action to an invisible activity ($where, $rec->key)");
                    $this->assertSame(activity_state::YES, $state->available, "Action to a locked activity ($where, $rec->key)");
                }
                if (str_starts_with($rec->key, 'not_passed')) {
                    $this->assertSame(activity_state::NO, $state->pass, "Fail message without a known fail ($where)");
                }
                if ($rec->key === 'not_passed_retry') {
                    $this->assertSame(activity_state::YES, $state->attempts_left(), "Retry promised ($where)");
                }
                if ($rec->key === 'not_passed_final') {
                    $this->assertSame(activity_state::NO, $state->attempts_left(), "No attempt left claimed ($where)");
                }
                if (str_starts_with($rec->key, 'deadline_')) {
                    $this->assertSame(activity_state::NO, $state->completion, "Deadline message for unknown completion ($where)");
                    $this->assertNotNull($state->deadline, $where);
                }
                if (str_starts_with($rec->key, 'locked_')) {
                    $this->assertSame(activity_state::NO, $state->available, "Lock message without a known lock ($where)");
                }
                if ($rec->key === 'course_complete') {
                    $this->assertSame(activity_state::YES, $ctx->coursecomplete, $where);
                }
                if (in_array($rec->key, ['success', 'success_next'], true)) {
                    $this->assertContains($rec->facts['outcome'], ['passed', 'completed'], $where);
                    $target = $states[$rec->key === 'success' ? $rec->targetcmid : array_key_first(array_filter(
                        $states,
                        static fn($s) => $s->label === $rec->facts['label']
                    ))];
                    $this->assertSame($rec->facts['outcome'] === 'passed', $target->pass === activity_state::YES, $where);
                }
            }

            // Deterministic and independent of the input order.
            $again = $evaluator->select($evaluator->candidates($states, self::NOW, $ctx));
            $this->assertEquals($selected, $again, $where);
            $shuffled = $candidates;
            shuffle($shuffled);
            $this->assertEquals($selected, $evaluator->select($shuffled), "Order of candidates matters ($where)");

            $next = $evaluator->next_transition($states, self::NOW);
            if ($next !== null) {
                $this->assertGreaterThan(self::NOW, $next, $where);
            }
        }
    }
}
