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

namespace slcmod_quiz;

use mod_sideline\local\adapter\base;
use mod_sideline\local\adapter\facts;

/**
 * Quiz facts: opening and closing time and the number of attempts, after user and group overrides.
 *
 * Group overrides follow the quiz module: the most lenient value of all of the user's groups
 * applies; a user override replaces them. Three queries for any number of quizzes.
 *
 * @package    slcmod_quiz
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class adapter extends base {
    #[\Override]
    public function modname(): string {
        return 'quiz';
    }

    #[\Override]
    public function collect(array $cms, int $userid, int $now): array {
        global $DB;

        $byinstance = [];
        foreach ($cms as $cm) {
            $byinstance[(int) $cm->instance] = $cm;
        }
        if (!$byinstance) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(array_keys($byinstance), SQL_PARAMS_NAMED);
        $quizzes = $DB->get_records_select('quiz', "id $insql", $params, '', 'id, course, timeopen, timeclose, attempts');
        if (!$quizzes) {
            return [];
        }
        $courseid = (int) reset($quizzes)->course;
        $groupids = groups_get_user_groups($courseid, $userid)[0] ?? [];

        $sql = "SELECT * FROM {quiz_overrides} WHERE quiz $insql AND (userid = :userid";
        $oparams = $params + ['userid' => $userid];
        if ($groupids) {
            [$gsql, $gparams] = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED, 'g');
            $sql .= " OR groupid $gsql";
            $oparams += $gparams;
        }
        $sql .= ')';
        $useroverride = [];
        $groupoverride = [];
        foreach ($DB->get_records_sql($sql, $oparams) as $row) {
            if (!empty($row->userid)) {
                $useroverride[(int) $row->quiz] = $row;
            } else {
                $groupoverride[(int) $row->quiz][] = $row;
            }
        }

        $attempts = $DB->get_records_sql_menu(
            "SELECT quiz, COUNT(1) FROM {quiz_attempts}
              WHERE quiz $insql AND userid = :userid AND preview = 0
           GROUP BY quiz",
            $params + ['userid' => $userid]
        );

        $result = [];
        foreach ($quizzes as $id => $quiz) {
            $open = (int) $quiz->timeopen;
            $close = (int) $quiz->timeclose;
            $allowed = (int) $quiz->attempts;
            if (!empty($groupoverride[$id])) {
                // Most lenient group value: earliest opening, latest closing, most attempts (0 = unlimited).
                $opens = array_filter(array_map(static fn($o) => $o->timeopen, $groupoverride[$id]), static fn($v) => $v !== null);
                $closes = array_filter(
                    array_map(static fn($o) => $o->timeclose, $groupoverride[$id]),
                    static fn($v) => $v !== null,
                );
                $tries = array_filter(array_map(static fn($o) => $o->attempts, $groupoverride[$id]), static fn($v) => $v !== null);
                if ($opens) {
                    $open = (int) min($opens);
                }
                if ($closes) {
                    $close = in_array(0, array_map('intval', $closes), true) ? 0 : (int) max($closes);
                }
                if ($tries) {
                    $allowed = in_array(0, array_map('intval', $tries), true) ? 0 : (int) max($tries);
                }
            }
            if (isset($useroverride[$id])) {
                $o = $useroverride[$id];
                $open = $o->timeopen !== null ? (int) $o->timeopen : $open;
                $close = $o->timeclose !== null ? (int) $o->timeclose : $close;
                $allowed = $o->attempts !== null ? (int) $o->attempts : $allowed;
            }
            $cm = $byinstance[(int) $id];
            $result[(int) $cm->id] = new facts(
                opensat: $open ?: null,
                deadline: $close ?: null,
                closesat: $close ?: null,
                attemptsused: (int) ($attempts[$id] ?? 0),
                attemptsallowed: $allowed,
            );
        }
        return $result;
    }

    #[\Override]
    public function invalidating_events(): array {
        return ['\mod_quiz\event\attempt_submitted', '\mod_quiz\event\attempt_started'];
    }
}
