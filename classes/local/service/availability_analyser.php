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
 * Reads an availability tree for two harmless facts: the earliest possible opening time and the
 * kind of condition that locks the activity (P1-04).
 *
 * Whether the activity is available is decided by core (cm_info::$available), never here. This
 * class only explains, and when the tree does not allow a safe statement it says "unknown"
 * (null) instead of guessing. AND, OR and negation are supported:
 *
 * - AND: the activity cannot open before the latest of the lower bounds of its children.
 * - OR: it can open as soon as any child allows it, so the bound is the earliest child bound,
 *   and unknown as soon as one child has no known bound.
 * - NOT: a negated "before t" is a "from t", other negations give no bound.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class availability_analyser {
    /**
     * The earliest time at which the activity can open, if a date condition determines it.
     *
     * @param string|null $json Availability JSON of the course module.
     * @param int $now Current time.
     * @return int|null Unix time in the future, or null when unknown or not date-bound.
     */
    public function earliest_opening(?string $json, int $now): ?int {
        $tree = $json ? json_decode($json) : null;
        // A condition the course hides from the learner must not be revealed in any form.
        if (!$tree || $this->has_hidden_condition($tree)) {
            return null;
        }
        $bound = $this->bound($tree, false);
        return ($bound !== null && $bound > $now) ? $bound : null;
    }

    /**
     * The kind of condition in a tree, for an explanation without details.
     *
     * @param string|null $json Availability JSON.
     * @return string One of the activity_state::LOCK_ constants.
     */
    public function lock_reason(?string $json): string {
        $tree = $json ? json_decode($json) : null;
        if (!$tree || $this->has_hidden_condition($tree)) {
            return activity_state::LOCK_OTHER;
        }
        $types = array_unique($this->types($tree));
        if (count($types) !== 1) {
            return activity_state::LOCK_OTHER;
        }
        return match (reset($types)) {
            'date' => activity_state::LOCK_DATE,
            'completion' => activity_state::LOCK_COMPLETION,
            'grade' => activity_state::LOCK_GRADE,
            'group', 'grouping' => activity_state::LOCK_GROUP,
            default => activity_state::LOCK_OTHER,
        };
    }

    /**
     * Lower bound of the opening time of a (sub)tree.
     *
     * @param \stdClass $node Tree or condition.
     * @param bool $negated Whether an odd number of negations is above this node.
     * @return int|null Bound, 0 when the node does not restrict time, null when unknown.
     */
    private function bound(\stdClass $node, bool $negated): ?int {
        if (isset($node->c) && is_array($node->c)) {
            $op = $node->op ?? '&';
            $neg = ($negated !== in_array($op, ['!&', '!|'], true));
            // De Morgan: a negated AND behaves like an OR of negated children and vice versa.
            $isand = (in_array($op, ['&', '!|'], true) !== $negated);
            $bounds = [];
            foreach ($node->c as $child) {
                $bounds[] = $this->bound($child, $neg);
            }
            if (!$bounds) {
                return 0;
            }
            if ($isand) {
                $known = array_filter($bounds, static fn($b) => $b !== null);
                return $known ? max($known) : null;
            }
            return in_array(null, $bounds, true) ? null : min($bounds);
        }
        if (($node->type ?? '') !== 'date' || !isset($node->t)) {
            // Not a date: it does not bound the time, but it may still lock.
            return 0;
        }
        $direction = $node->d ?? '';
        $from = ($direction === '>=' && !$negated) || ($direction === '<' && $negated);
        return $from ? (int) $node->t : 0;
    }

    /**
     * All condition types in a tree.
     *
     * @param \stdClass $node Tree or condition.
     * @return string[]
     */
    private function types(\stdClass $node): array {
        if (isset($node->c) && is_array($node->c)) {
            $types = [];
            foreach ($node->c as $child) {
                $types = array_merge($types, $this->types($child));
            }
            return $types;
        }
        return [(string) ($node->type ?? 'other')];
    }

    /**
     * Whether any part of the tree is hidden from the learner (showc false in an AND, show false in an OR).
     *
     * @param \stdClass $node Tree.
     * @return bool
     */
    private function has_hidden_condition(\stdClass $node): bool {
        if (!isset($node->c) || !is_array($node->c)) {
            return false;
        }
        if (isset($node->show) && !$node->show) {
            return true;
        }
        if (isset($node->showc) && is_array($node->showc) && in_array(false, $node->showc, true)) {
            return true;
        }
        foreach ($node->c as $child) {
            if ($this->has_hidden_condition($child)) {
                return true;
            }
        }
        return false;
    }
}
