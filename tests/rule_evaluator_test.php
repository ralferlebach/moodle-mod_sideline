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

/**
 * Tests for the rule evaluator: precedence, unknown handling, transitions.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_sideline\local\service\rule_evaluator
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_sideline\local\service\rule_evaluator::class)]
final class rule_evaluator_test extends \advanced_testcase {
    /** @var int A fixed "now" so no test depends on the wall clock. */
    private const NOW = 1800000000;

    /**
     * Build a state with sensible defaults.
     *
     * @param array $overrides Constructor arguments to override.
     * @return activity_state
     */
    private function state(array $overrides = []): activity_state {
        return new activity_state(...($overrides + [
            'cmid' => 1, 'label' => 'Quiz 1', 'visible' => true,
            'available' => activity_state::YES, 'completion' => activity_state::NO,
        ]));
    }

    /**
     * Keys of the evaluated recommendations.
     *
     * @param activity_state[] $states States.
     * @return string[]
     */
    private function keys(array $states): array {
        return array_map(static fn($r) => $r->key, (new rule_evaluator())->evaluate($states, self::NOW));
    }

    public function test_nothing_known_gives_small_talk_alone(): void {
        $this->assertSame(['smalltalk'], $this->keys([]));
    }

    public function test_deadline_has_precedence_over_success(): void {
        $keys = $this->keys([
            $this->state(['cmid' => 1, 'completion' => activity_state::YES, 'pass' => activity_state::YES,
                'lastactivity' => self::NOW - 100]),
            $this->state(['cmid' => 2, 'label' => 'Essay', 'deadline' => self::NOW + DAYSECS]),
        ]);
        $this->assertSame('deadline_soon', $keys[0]);
        $this->assertContains('success_next', $keys);
    }

    public function test_at_most_one_main_message_and_two_supplements(): void {
        $keys = $this->keys([
            $this->state(['cmid' => 1, 'deadline' => self::NOW + DAYSECS]),
            $this->state(['cmid' => 2, 'deadline' => self::NOW + 2 * DAYSECS]),
            $this->state(['cmid' => 3, 'pass' => activity_state::NO, 'completion' => activity_state::YES]),
            $this->state(['cmid' => 4, 'completion' => activity_state::YES]),
        ]);
        $this->assertCount(1 + rule_evaluator::MAX_SUPPLEMENTS, $keys);
    }

    public function test_unknown_completion_is_never_treated_as_incomplete(): void {
        // Deadline known, completion unknown: no deadline message may claim the activity is open.
        $keys = $this->keys([$this->state(['completion' => activity_state::UNKNOWN, 'deadline' => self::NOW + DAYSECS])]);
        $this->assertNotContains('deadline_soon', $keys);
        $this->assertNotContains('deadline_passed', $keys);
    }

    public function test_unknown_pass_is_not_a_failure(): void {
        $keys = $this->keys([$this->state(['completion' => activity_state::YES, 'pass' => activity_state::UNKNOWN])]);
        $this->assertNotContains('not_passed', $keys);
        $this->assertContains('success', $keys);
    }

    public function test_failed_activity_yields_encouragement_without_retry_promise(): void {
        $recs = (new rule_evaluator())->evaluate([
            $this->state(['completion' => activity_state::YES, 'pass' => activity_state::NO]),
        ], self::NOW);
        $this->assertSame('not_passed', $recs[0]->key);
    }

    public function test_passed_deadline_is_stated_factually(): void {
        $this->assertSame('deadline_passed', $this->keys([$this->state(['deadline' => self::NOW - 10])])[0]);
    }

    public function test_deadline_far_away_is_not_mentioned(): void {
        $this->assertNotContains('deadline_soon', $this->keys([$this->state(['deadline' => self::NOW + 30 * DAYSECS])]));
    }

    public function test_locked_activity_names_opening_time_only_when_known(): void {
        $known = (new rule_evaluator())->evaluate([
            $this->state(['available' => activity_state::NO, 'opensat' => self::NOW + 5 * DAYSECS]),
        ], self::NOW);
        $this->assertSame('locked_opens', $known[0]->key);
        $this->assertSame(self::NOW + 5 * DAYSECS, $known[0]->facts['opensat']);

        $unknown = (new rule_evaluator())->evaluate([$this->state(['available' => activity_state::NO])], self::NOW);
        $this->assertSame('locked_requirements', $unknown[0]->key);
        $this->assertArrayNotHasKey('opensat', $unknown[0]->facts);
    }

    public function test_welcome_back_needs_a_known_old_activity(): void {
        $old = $this->keys([$this->state(['lastactivity' => self::NOW - 20 * DAYSECS])]);
        $this->assertContains('welcome_back', $old);

        // No recorded activity at all is unknown, not a pause.
        $this->assertNotContains('welcome_back', $this->keys([$this->state()]));
        // Recent activity is not a pause.
        $this->assertNotContains('welcome_back', $this->keys([$this->state(['lastactivity' => self::NOW - DAYSECS])]));
    }

    public function test_ordering_is_deterministic(): void {
        $states = [
            $this->state(['cmid' => 3, 'deadline' => self::NOW + DAYSECS]),
            $this->state(['cmid' => 2, 'deadline' => self::NOW + DAYSECS]),
        ];
        $first = $this->keys($states);
        $this->assertSame($first, $this->keys(array_reverse($states)));
    }

    public function test_next_transition_is_the_earliest_future_moment(): void {
        $evaluator = new rule_evaluator();
        $deadline = self::NOW + 10 * DAYSECS;
        $next = $evaluator->next_transition([$this->state(['deadline' => $deadline])], self::NOW);
        // The earlier of: the deadline window opening, or the safety lifetime.
        $this->assertSame(self::NOW + rule_evaluator::SAFETY_TTL, $next);

        $soon = $evaluator->next_transition([$this->state(['deadline' => self::NOW + 2 * DAYSECS])], self::NOW);
        $this->assertSame(self::NOW + rule_evaluator::SAFETY_TTL, $soon);

        $opens = $evaluator->next_transition(
            [$this->state(['available' => activity_state::NO, 'opensat' => self::NOW + 600])],
            self::NOW
        );
        $this->assertSame(self::NOW + 600, $opens);
    }

    public function test_next_transition_is_never_in_the_past(): void {
        $next = (new rule_evaluator())->next_transition([$this->state(['deadline' => self::NOW - DAYSECS])], self::NOW);
        $this->assertGreaterThan(self::NOW, $next);
    }
}
