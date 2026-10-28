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
use mod_sideline\local\rules\rule_schema;

/**
 * Evaluates the built-in rules and resolves collisions deterministically (P-05, P-06).
 *
 * Built-in rules are fixed in code and carry a version number. Custom rules (P1-09) are validated
 * data with a closed set of conditions; they take part in the same deterministic ordering and may
 * have a cooldown and a weekly limit. A rule that is currently shown stays eligible, so a cooldown
 * never makes the text flicker.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rule_evaluator {
    /** @var int Version of the built-in rule set. Bump on every behavioural change. */
    public const RULES_VERSION = 3;

    /** @var int Seconds before a deadline in which the deadline rule applies. */
    public const DEADLINE_WINDOW = 3 * DAYSECS;

    /** @var int Seconds without any recorded activity after which the welcome-back rule applies. */
    public const PAUSE_AFTER = 14 * DAYSECS;

    /** @var int Upper bound of the cache lifetime in seconds, for changes no event reports. */
    public const SAFETY_TTL = 6 * HOURSECS;

    /** @var int Number of supplementary messages next to the main message. */
    public const MAX_SUPPLEMENTS = 2;

    /** @var int Deadline window when the learner asked for more focus on deadlines. */
    public const FOCUS_DEADLINE_WINDOW = 7 * DAYSECS;

    /** @var evaluation_context Context of the current evaluation. */
    private evaluation_context $ctx;

    /** @var int[] Times at which a cooldown or weekly limit ends, collected during evaluation. */
    private array $cooldownends = [];

    /**
     * Evaluate all rules and return the ordered recommendations: main message first.
     *
     * @param activity_state[] $states Observed activities.
     * @param int $now Current time.
     * @param evaluation_context|null $ctx Answers, course state, custom rules, group facts.
     * @return recommendation[] At most 1 + MAX_SUPPLEMENTS entries, never empty.
     */
    public function evaluate(array $states, int $now, ?evaluation_context $ctx = null): array {
        return $this->select($this->candidates($states, $now, $ctx));
    }

    /**
     * Every candidate before selection, for the administrator debug export.
     *
     * @param activity_state[] $states Observed activities.
     * @param int $now Current time.
     * @param evaluation_context|null $ctx Context.
     * @return recommendation[]
     */
    public function candidates(array $states, int $now, ?evaluation_context $ctx = null): array {
        $this->ctx = $ctx ?? new evaluation_context();
        $this->cooldownends = [];
        $candidates = array_merge(
            $this->rule_course_complete(),
            $this->rule_deadlines($states, $now),
            $this->rule_locked($states, $now),
            $this->rule_opens_later($states, $now),
            $this->rule_failed($states),
            $this->rule_submitted($states),
            $this->rule_success_next($states),
            $this->rule_welcome_back($states, $now),
            $this->rule_small_step($states),
            $this->rule_orientation($states),
            $this->rule_group(),
            $this->rule_custom($states, $now),
        );
        $candidates[] = new recommendation('smalltalk', recommendation::CAT_SMALLTALK, 100);
        return $candidates;
    }

    /**
     * Deterministic selection: one main message and a bounded number of supplements.
     *
     * @param recommendation[] $candidates Candidates.
     * @return recommendation[]
     */
    public function select(array $candidates): array {
        // Deterministic order: category, priority, then the key as a stable tie-breaker.
        usort($candidates, static function (recommendation $a, recommendation $b): int {
            return [$a->category, $a->priority, $a->key, (int) $a->targetcmid]
                <=> [$b->category, $b->priority, $b->key, (int) $b->targetcmid];
        });

        // One main message plus a bounded number of supplements, never two from the same rule.
        $selected = [];
        $usedkeys = [];
        foreach ($candidates as $candidate) {
            if (isset($usedkeys[$candidate->key])) {
                continue;
            }
            // Small talk only ever stands alone.
            if ($candidate->category === recommendation::CAT_SMALLTALK && $selected) {
                continue;
            }
            $selected[] = $candidate;
            $usedkeys[$candidate->key] = true;
            if (count($selected) > self::MAX_SUPPLEMENTS) {
                break;
            }
        }
        return $selected;
    }

    /**
     * The earliest moment at which the outcome may change without any event (L-10, P-28).
     *
     * @param activity_state[] $states Observed activities.
     * @param int $now Current time.
     * @return int Unix time, never later than now plus the safety lifetime.
     */
    public function next_transition(array $states, int $now): int {
        $times = array_merge([$now + self::SAFETY_TTL], $this->cooldownends);
        foreach ($states as $state) {
            if ($state->completion !== activity_state::YES && $state->deadline) {
                // The moment the deadline window opens, and the deadline itself.
                $times[] = $state->deadline - self::DEADLINE_WINDOW;
                $times[] = $state->deadline;
            }
            if ($state->opensat) {
                $times[] = $state->opensat;
            }
            if ($state->lastactivity) {
                $times[] = $state->lastactivity + self::PAUSE_AFTER;
            }
            if ($state->lastused) {
                $times[] = $state->lastused + self::PAUSE_AFTER;
            }
            if ($state->completion !== activity_state::YES && $state->deadline) {
                $times[] = $state->deadline - self::FOCUS_DEADLINE_WINDOW;
            }
        }
        $future = array_filter($times, static fn(int $t): bool => $t > $now);
        return $future ? min($future) : $now + self::SAFETY_TTL;
    }

    /**
     * Deadlines: factual, highest precedence.
     *
     * @param activity_state[] $states Activities.
     * @param int $now Current time.
     * @return recommendation[]
     */
    private function rule_deadlines(array $states, int $now): array {
        $result = [];
        foreach ($states as $state) {
            // Only a known incomplete state counts: unknown is never treated as incomplete.
            if (!$state->visible || $state->completion !== activity_state::NO || !$state->deadline) {
                continue;
            }
            $facts = ['label' => $state->label, 'deadline' => $state->deadline];
            $window = $this->ctx->has_effect('focus_deadlines') ? self::FOCUS_DEADLINE_WINDOW : self::DEADLINE_WINDOW;
            if ($state->deadline > $now && $state->deadline - $now <= $window) {
                $result[] = new recommendation(
                    'deadline_soon',
                    recommendation::CAT_SAFETY,
                    $state->deadline,
                    $state->available === activity_state::YES ? recommendation::ACTION_OPEN : recommendation::ACTION_NONE,
                    $state->cmid,
                    $facts,
                    $state->deadline
                );
            } else if ($state->deadline <= $now) {
                $result[] = new recommendation(
                    'deadline_passed',
                    recommendation::CAT_SAFETY,
                    1000 + $state->deadline,
                    $state->available === activity_state::YES ? recommendation::ACTION_OPEN : recommendation::ACTION_NONE,
                    $state->cmid,
                    $facts
                );
            }
        }
        return $result;
    }

    /**
     * Locked activities: explain the situation, name the opening time only when it is known.
     *
     * @param activity_state[] $states Activities.
     * @param int $now Current time.
     * @return recommendation[]
     */
    private function rule_locked(array $states, int $now): array {
        $result = [];
        foreach ($states as $state) {
            if ($state->available !== activity_state::NO) {
                continue;
            }
            $facts = ['label' => $state->label];
            if ($state->opensat && $state->opensat > $now) {
                $facts['opensat'] = $state->opensat;
                $result[] = new recommendation(
                    'locked_opens',
                    recommendation::CAT_NEXT,
                    $state->opensat,
                    recommendation::ACTION_NONE,
                    $state->cmid,
                    $facts,
                    $state->opensat
                );
            } else {
                $key = match ($state->lockreason) {
                    activity_state::LOCK_COMPLETION => 'locked_completion',
                    activity_state::LOCK_GRADE => 'locked_grade',
                    activity_state::LOCK_GROUP => 'locked_group',
                    default => 'locked_requirements',
                };
                $result[] = new recommendation(
                    $key,
                    recommendation::CAT_NEXT,
                    5000 + $state->cmid,
                    recommendation::ACTION_NONE,
                    $state->cmid,
                    $facts
                );
            }
        }
        return $result;
    }

    /**
     * Failed but completed activities: encourage, never promise options we cannot verify.
     *
     * @param activity_state[] $states Activities.
     * @return recommendation[]
     */
    private function rule_failed(array $states): array {
        $result = [];
        foreach ($states as $state) {
            if ($state->visible && $state->pass === activity_state::NO) {
                // Promise another try only when the module confirms one; never imply there is none otherwise.
                $left = $state->attempts_left();
                $key = match ($left) {
                    activity_state::YES => 'not_passed_retry',
                    activity_state::NO => 'not_passed_final',
                    default => 'not_passed',
                };
                $open = $state->available === activity_state::YES && $left !== activity_state::NO;
                $result[] = new recommendation(
                    $key,
                    recommendation::CAT_NEXT,
                    2000 + $state->cmid,
                    $open ? recommendation::ACTION_OPEN : recommendation::ACTION_NONE,
                    $state->cmid,
                    ['label' => $state->label]
                );
            }
        }
        return $result;
    }

    /**
     * Success: acknowledge the most recent success and point to the next open activity.
     *
     * @param activity_state[] $states Activities.
     * @return recommendation[]
     */
    private function rule_success_next(array $states): array {
        $latest = null;
        foreach ($states as $state) {
            $succeeded = $state->pass === activity_state::YES
                || ($state->completion === activity_state::YES && $state->pass !== activity_state::NO);
            if ($succeeded && ($latest === null || (int) $state->lastactivity > (int) $latest->lastactivity)) {
                $latest = $state;
            }
        }
        if ($latest === null) {
            return [];
        }
        // A completion without a pass is not a pass: the wording may say "passed" only for a pass.
        $outcome = $latest->pass === activity_state::YES ? 'passed' : 'completed';
        $next = null;
        foreach ($states as $state) {
            if ($state->visible && $state->available === activity_state::YES && $state->completion === activity_state::NO) {
                $next = $state;
                break;
            }
        }
        if ($next === null) {
            return [new recommendation(
                'success',
                recommendation::CAT_SUCCESS,
                10,
                recommendation::ACTION_NONE,
                $latest->cmid,
                ['label' => $latest->label, 'outcome' => $outcome]
            )];
        }
        return [new recommendation(
            'success_next',
            recommendation::CAT_SUCCESS,
            1,
            recommendation::ACTION_OPEN,
            $next->cmid,
            ['label' => $latest->label, 'nextlabel' => $next->label, 'outcome' => $outcome]
        )];
    }

    /**
     * Return after a pause: a low-threshold way back in, with no assumption about the reason.
     *
     * @param activity_state[] $states Activities.
     * @param int $now Current time.
     * @return recommendation[]
     */
    private function rule_welcome_back(array $states, int $now): array {
        $last = null;
        foreach ($states as $state) {
            foreach ([$state->lastactivity, $state->lastused] as $time) {
                if ($time) {
                    $last = max((int) $last, $time);
                }
            }
        }
        // No recorded activity at all is unknown, not a pause.
        if ($last === null || $now - $last < self::PAUSE_AFTER) {
            return [];
        }
        foreach ($states as $state) {
            if ($state->visible && $state->available === activity_state::YES && $state->completion === activity_state::NO) {
                return [new recommendation(
                    $this->ctx->has_effect('pause_ok') ? 'welcome_back_gentle' : 'welcome_back',
                    recommendation::CAT_NEXT,
                    3000,
                    recommendation::ACTION_OPEN,
                    $state->cmid,
                    ['nextlabel' => $state->label]
                )];
            }
        }
        return [];
    }

    /**
     * Orientation: how many of the observed activities are done, when completion is known.
     *
     * @param activity_state[] $states Activities.
     * @return recommendation[]
     */
    private function rule_orientation(array $states): array {
        $tracked = 0;
        $done = 0;
        foreach ($states as $state) {
            if ($state->completion !== activity_state::UNKNOWN) {
                $tracked++;
                $done += $state->completion === activity_state::YES ? 1 : 0;
            }
        }
        // The learner said pauses are fine: no counting.
        if ($tracked === 0 || $this->ctx->has_effect('pause_ok')) {
            return [];
        }
        return [new recommendation(
            'progress',
            recommendation::CAT_ORIENTATION,
            1,
            recommendation::ACTION_NONE,
            null,
            ['done' => $done, 'total' => $tracked]
        )];
    }

    /**
     * Course completion of the instance's course.
     *
     * @return recommendation[]
     */
    private function rule_course_complete(): array {
        if ($this->ctx->coursecomplete !== activity_state::YES) {
            return [];
        }
        return [new recommendation('course_complete', recommendation::CAT_SUCCESS, 0)];
    }

    /**
     * Activities that can be seen and are available, but whose module opens later (adapter facts).
     *
     * @param activity_state[] $states Activities.
     * @param int $now Current time.
     * @return recommendation[]
     */
    private function rule_opens_later(array $states, int $now): array {
        $result = [];
        foreach ($states as $state) {
            if (
                $state->visible && $state->available === activity_state::YES && $state->opensat && $state->opensat > $now
                    && $state->completion !== activity_state::YES
            ) {
                $result[] = new recommendation(
                    'opens_later',
                    recommendation::CAT_NEXT,
                    $state->opensat,
                    recommendation::ACTION_NONE,
                    $state->cmid,
                    ['label' => $state->label, 'opensat' => $state->opensat],
                    $state->opensat
                );
            }
        }
        return $result;
    }

    /**
     * Submitted work waiting for feedback.
     *
     * @param activity_state[] $states Activities.
     * @return recommendation[]
     */
    private function rule_submitted(array $states): array {
        $result = [];
        foreach ($states as $state) {
            if (
                $state->visible && $state->submitted === activity_state::YES && $state->grade === null
                    && $state->completion !== activity_state::YES
            ) {
                $result[] = new recommendation(
                    'submitted_waiting',
                    recommendation::CAT_ORIENTATION,
                    2,
                    recommendation::ACTION_NONE,
                    $state->cmid,
                    ['label' => $state->label]
                );
            }
        }
        return $result;
    }

    /**
     * The learner asked for small steps: suggest one concrete next activity.
     *
     * @param activity_state[] $states Activities.
     * @return recommendation[]
     */
    private function rule_small_step(array $states): array {
        if (!$this->ctx->has_effect('small_steps')) {
            return [];
        }
        foreach ($states as $state) {
            if ($state->visible && $state->available === activity_state::YES && $state->completion === activity_state::NO) {
                return [new recommendation(
                    'small_step',
                    recommendation::CAT_NEXT,
                    2500,
                    recommendation::ACTION_OPEN,
                    $state->cmid,
                    ['nextlabel' => $state->label]
                )];
            }
        }
        return [];
    }

    /**
     * Group statements prepared and privacy-checked by the group service (G5).
     *
     * @return recommendation[]
     */
    private function rule_group(): array {
        $result = [];
        foreach ($this->ctx->groupfacts as $index => $fact) {
            $result[] = new recommendation(
                (string) $fact['key'],
                recommendation::CAT_ORIENTATION,
                5 + $index,
                recommendation::ACTION_NONE,
                null,
                array_intersect_key($fact, array_flip(['label', 'count', 'group', 'names']))
            );
        }
        return $result;
    }

    /**
     * Custom rules of the instance (P1-09).
     *
     * @param activity_state[] $states Activities.
     * @param int $now Current time.
     * @return recommendation[]
     */
    private function rule_custom(array $states, int $now): array {
        $result = [];
        foreach ($this->ctx->customrules as $rule) {
            if (!$this->eligible($rule, $now)) {
                continue;
            }
            $target = $this->match_custom($rule, $states, $now);
            if ($target === false) {
                continue;
            }
            $facts = ['templates' => $rule['templates'], 'templateversion' => max(1, (int) ($rule['templateversion'] ?? 1))];
            if ($target) {
                $facts['label'] = $target->label;
                if ($target->deadline) {
                    $facts['deadline'] = $target->deadline;
                }
            }
            [$done, $total] = $this->progress_counts($states);
            if ($total) {
                $facts['done'] = $done;
                $facts['total'] = $total;
            }
            $open = $rule['action'] === 'open' && $target && $target->available === activity_state::YES;
            $result[] = new recommendation(
                $rule['key'],
                rule_schema::CATEGORIES[$rule['category']] ?? recommendation::CAT_NEXT,
                (int) $rule['priority'],
                $open ? recommendation::ACTION_OPEN : recommendation::ACTION_NONE,
                $target?->cmid,
                $facts
            );
        }
        return $result;
    }

    /**
     * Cooldown and weekly limit of a custom rule. A rule that is shown right now stays eligible.
     *
     * @param array $rule Rule definition.
     * @param int $now Current time.
     * @return bool
     */
    private function eligible(array $rule, int $now): bool {
        if (in_array($rule['key'], $this->ctx->currentkeys, true)) {
            return true;
        }
        $hits = $this->ctx->hits[$rule['key']] ?? [];
        if ($rule['cooldown'] && $hits && $now - $hits[0] < $rule['cooldown']) {
            $this->cooldownends[] = $hits[0] + $rule['cooldown'];
            return false;
        }
        if ($rule['maxperweek']) {
            $recent = array_values(array_filter($hits, static fn(int $t): bool => $t > $now - WEEKSECS));
            if (count($recent) >= $rule['maxperweek']) {
                $this->cooldownends[] = min($recent) + WEEKSECS;
                return false;
            }
        }
        return true;
    }

    /**
     * Evaluate the conditions of a custom rule.
     *
     * @param array $rule Rule definition.
     * @param activity_state[] $states Activities.
     * @param int $now Current time.
     * @return activity_state|null|false The target activity, null for a rule without target, false when it does not match.
     */
    private function match_custom(array $rule, array $states, int $now): activity_state|null|false {
        $activitytypes = ['activity', 'grade', 'deadline_within', 'unused_for'];
        $hasany = false;
        $firstspecific = null;
        foreach ($rule['conditions'] as $c) {
            if (in_array($c['type'], $activitytypes, true)) {
                if ((int) $c['cmid'] === 0) {
                    $hasany = true;
                } else if ($firstspecific === null) {
                    $firstspecific = (int) $c['cmid'];
                }
            }
        }
        $candidates = $hasany ? array_filter($states, static fn(activity_state $s): bool => $s->visible) : [null];
        foreach ($candidates as $candidate) {
            $values = [];
            foreach ($rule['conditions'] as $c) {
                $values[] = $this->condition_holds($c, $candidate, $states, $now);
            }
            $matched = $rule['match'] === 'any' ? in_array(true, $values, true) : !in_array(false, $values, true);
            if ($matched) {
                if ($candidate) {
                    return $candidate;
                }
                return $firstspecific && isset($states[$firstspecific]) ? $states[$firstspecific] : null;
            }
        }
        return false;
    }

    /**
     * Truth of one custom condition. Unknown values never satisfy a condition.
     *
     * @param array $c Condition.
     * @param activity_state|null $candidate Candidate activity for conditions with cmid 0.
     * @param activity_state[] $states All activities.
     * @param int $now Current time.
     * @return bool
     */
    private function condition_holds(array $c, ?activity_state $candidate, array $states, int $now): bool {
        $s = null;
        if (isset($c['cmid'])) {
            $s = (int) $c['cmid'] === 0 ? $candidate : ($states[(int) $c['cmid']] ?? null);
        }
        switch ($c['type']) {
            case 'activity':
                if (!$s) {
                    return false;
                }
                $value = $c['field'] === 'attempts_left' ? $s->attempts_left() : $s->{$c['field']};
                return $value === $c['is'];
            case 'grade':
                if (!$s || $s->grade === null) {
                    return false;
                }
                return $c['op'] === 'lt' ? $s->grade < $c['value'] : $s->grade >= $c['value'];
            case 'deadline_within':
                return $s && $s->deadline && $s->completion !== activity_state::YES
                    && $s->deadline > $now && $s->deadline - $now <= $c['seconds'];
            case 'unused_for':
                return $s && $s->lastused !== null && $now - $s->lastused >= $c['seconds'];
            case 'progress':
                [$done, $total] = $this->progress_counts($states);
                if (!$total) {
                    return false;
                }
                $percent = 100 * $done / $total;
                return $c['op'] === 'lt' ? $percent < $c['value'] : $percent >= $c['value'];
            case 'course_complete':
                return $this->ctx->coursecomplete === $c['is'];
            case 'effect':
                return $this->ctx->has_effect($c['effect']);
        }
        return false;
    }

    /**
     * Done and tracked activities.
     *
     * @param activity_state[] $states Activities.
     * @return int[] [done, total]
     */
    private function progress_counts(array $states): array {
        $tracked = 0;
        $done = 0;
        foreach ($states as $state) {
            if ($state->completion !== activity_state::UNKNOWN) {
                $tracked++;
                $done += $state->completion === activity_state::YES ? 1 : 0;
            }
        }
        return [$done, $tracked];
    }
}
