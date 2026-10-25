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

namespace slcmod_assign;

use mod_sideline\local\adapter\base;
use mod_sideline\local\adapter\facts;

/**
 * Assignment facts: opening, due and cut-off dates after user and group overrides and extensions,
 * and whether the latest submission is submitted.
 *
 * Four queries for any number of assignments: instances, overrides, extensions, submissions.
 *
 * @package    slcmod_assign
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class adapter extends base {
    #[\Override]
    public function modname(): string {
        return 'assign';
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
        $instances = $DB->get_records_select(
            'assign',
            "id $insql",
            $params,
            '',
            'id, course, allowsubmissionsfromdate, duedate, cutoffdate, teamsubmission'
        );
        if (!$instances) {
            return [];
        }
        $courseid = (int) reset($instances)->course;
        $groupids = groups_get_user_groups($courseid, $userid)[0] ?? [];

        // Overrides: the user override wins, otherwise the group override with the lowest sortorder.
        $overrides = [];
        $sql = "SELECT * FROM {assign_overrides} WHERE assignid $insql AND (userid = :userid";
        $oparams = $params + ['userid' => $userid];
        if ($groupids) {
            [$gsql, $gparams] = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED, 'g');
            $sql .= " OR groupid $gsql";
            $oparams += $gparams;
        }
        // User overrides first (NULL ordering differs between databases, so sort on an explicit flag).
        $sql .= ') ORDER BY CASE WHEN userid IS NULL THEN 1 ELSE 0 END, sortorder ASC';
        foreach ($DB->get_records_sql($sql, $oparams) as $row) {
            $overrides[(int) $row->assignid] = $overrides[(int) $row->assignid] ?? $row;
        }

        $extensions = $DB->get_records_select_menu(
            'assign_user_flags',
            "assignment $insql AND userid = :userid AND extensionduedate > 0",
            $params + ['userid' => $userid],
            '',
            'assignment, extensionduedate'
        );

        $submitted = [];
        $rows = $DB->get_records_select(
            'assign_submission',
            "assignment $insql AND userid = :userid AND latest = 1",
            $params + ['userid' => $userid],
            '',
            'id, assignment, status'
        );
        foreach ($rows as $row) {
            $submitted[(int) $row->assignment] = $row->status === 'submitted';
        }

        $result = [];
        foreach ($instances as $id => $assign) {
            $override = $overrides[(int) $id] ?? null;
            $open = (int) ($override->allowsubmissionsfromdate ?? $assign->allowsubmissionsfromdate);
            $due = (int) ($override->duedate ?? $assign->duedate);
            $cutoff = (int) ($override->cutoffdate ?? $assign->cutoffdate);
            if (!empty($extensions[$id])) {
                $due = max($due, (int) $extensions[$id]);
                $cutoff = $cutoff ? max($cutoff, (int) $extensions[$id]) : 0;
            }
            // Team submissions belong to a group, not to the user: their state stays unknown here.
            $state = empty($assign->teamsubmission) ? ($submitted[(int) $id] ?? false) : null;
            $cm = $byinstance[(int) $id];
            $result[(int) $cm->id] = new facts(
                opensat: $open ?: null,
                deadline: $due ?: null,
                closesat: $cutoff ?: null,
                submitted: $state,
            );
        }
        return $result;
    }

    #[\Override]
    public function invalidating_events(): array {
        return ['\mod_assign\event\assessable_submitted', '\mod_assign\event\extension_granted'];
    }
}
