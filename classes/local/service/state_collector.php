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

use mod_sideline\local\adapter\registry;
use mod_sideline\local\model\activity_state;

/**
 * Collects the state of observed activities from Moodle core APIs and slcmod_* adapters (P-01 to P-03, P1-04).
 *
 * Core APIs are the authority: completion from completion_info, availability from course_modinfo,
 * grades from the gradebook, usage from the standard log store. Each data source is read with a
 * bounded number of queries per course, never one query per activity (N-02).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class state_collector {
    /** @var string[] Module names whose adapters failed during the last collect(). */
    public array $failedadapters = [];

    /** @var array Course id to course state (yes, no or unknown) of the last collect(). */
    public array $coursecompletion = [];

    /**
     * Collect the state of the given activities for one user.
     *
     * @param \stdClass $course The course of the Sideline instance (kept for compatibility).
     * @param int $userid User id.
     * @param \cm_info[] $cms Observed activities, possibly from several courses.
     * @param int|null $now Current time.
     * @return activity_state[] Keyed by course module id, in the given order.
     */
    public function collect(\stdClass $course, int $userid, array $cms, ?int $now = null): array {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        $now = $now ?? time();
        $this->failedadapters = [];
        $this->coursecompletion = [];
        if (!$cms) {
            return [];
        }

        $bycourse = [];
        foreach ($cms as $cm) {
            $bycourse[(int) $cm->course][$cm->id] = $cm;
        }

        [$adapterfacts, $this->failedadapters] = registry::collect($cms, $userid, $now);
        $usage = $this->load_usage($userid, array_keys($cms));
        $analyser = new availability_analyser();

        $states = [];
        foreach ($bycourse as $courseid => $coursecms) {
            $courserecord = get_course($courseid);
            $completion = new \completion_info($courserecord);
            $grades = $this->load_grades($courseid, $userid);
            $this->coursecompletion[$courseid] = $this->course_state($completion, $userid);
            foreach ($coursecms as $cm) {
                $states[$cm->id] = $this->state_for(
                    $cm,
                    $completion,
                    $grades,
                    $userid,
                    $adapterfacts[$cm->id] ?? null,
                    $usage[$cm->id] ?? null,
                    $analyser,
                    $now
                );
            }
        }

        // Keep the order of the scope.
        $ordered = [];
        foreach ($cms as $cm) {
            $ordered[$cm->id] = $states[$cm->id];
        }
        return $ordered;
    }

    /**
     * Course completion state of a course for the user.
     *
     * @param \completion_info $completion Completion info.
     * @param int $userid User id.
     * @return string yes, no or unknown.
     */
    private function course_state(\completion_info $completion, int $userid): string {
        if (!$completion->is_enabled() || !$completion->has_criteria()) {
            return activity_state::UNKNOWN;
        }
        return $completion->is_course_complete($userid) ? activity_state::YES : activity_state::NO;
    }

    /**
     * Build the state of a single activity.
     *
     * @param \cm_info $cm Activity.
     * @param \completion_info $completion Completion API for the course.
     * @param array $grades Grade rows keyed by "modname:instance".
     * @param int $userid User id.
     * @param \mod_sideline\local\adapter\facts|null $facts Adapter facts.
     * @param int|null $lastused Last use from the log.
     * @param availability_analyser $analyser Availability explanations.
     * @param int $now Current time.
     * @return activity_state
     */
    private function state_for(
        \cm_info $cm,
        \completion_info $completion,
        array $grades,
        int $userid,
        ?\mod_sideline\local\adapter\facts $facts,
        ?int $lastused,
        availability_analyser $analyser,
        int $now,
    ): activity_state {
        $available = $cm->available ? activity_state::YES : activity_state::NO;
        if (!$cm->uservisible && empty($cm->availableinfo)) {
            $available = activity_state::UNKNOWN;
        }

        $completionvalue = activity_state::UNKNOWN;
        $pass = activity_state::UNKNOWN;
        $lastactivity = null;
        $quality = 'ok';

        if ($completion->is_enabled($cm) != COMPLETION_TRACKING_NONE) {
            $data = $completion->get_data($cm, true, $userid);
            switch ((int) $data->completionstate) {
                case COMPLETION_COMPLETE:
                    $completionvalue = activity_state::YES;
                    break;
                case COMPLETION_COMPLETE_PASS:
                    $completionvalue = activity_state::YES;
                    $pass = activity_state::YES;
                    break;
                case COMPLETION_COMPLETE_FAIL:
                    $completionvalue = activity_state::YES;
                    $pass = activity_state::NO;
                    break;
                default:
                    $completionvalue = activity_state::NO;
            }
            if (!empty($data->timemodified)) {
                $lastactivity = (int) $data->timemodified;
            }
        } else {
            $quality = 'partial';
        }

        $gradepercent = null;
        $row = $grades[$cm->modname . ':' . $cm->instance] ?? null;
        if ($row && !$row->hidden && $row->finalgrade !== null && (float) $row->grademax > 0) {
            $gradepercent = round(100 * (float) $row->finalgrade / (float) $row->grademax, 1);
            if ($pass === activity_state::UNKNOWN && (float) $row->gradepass > 0) {
                $pass = (float) $row->finalgrade >= (float) $row->gradepass ? activity_state::YES : activity_state::NO;
            }
            if (!empty($row->timemodified)) {
                $lastactivity = max((int) $lastactivity, (int) $row->timemodified);
            }
        }

        // Module dates (after overrides) take precedence over the expected completion date.
        $deadline = $facts?->deadline ?? (!empty($cm->completionexpected) ? (int) $cm->completionexpected : null);

        $opensat = null;
        $lockreason = null;
        if ($available === activity_state::NO) {
            $opensat = $analyser->earliest_opening($cm->availability ?: null, $now);
            $lockreason = $analyser->lock_reason($cm->availability ?: null);
        }
        if ($facts?->opensat && $facts->opensat > $now) {
            $opensat = $opensat ? max($opensat, $facts->opensat) : $facts->opensat;
        }

        $submitted = activity_state::UNKNOWN;
        if ($facts !== null && $facts->submitted !== null) {
            $submitted = $facts->submitted ? activity_state::YES : activity_state::NO;
        }

        return new activity_state(
            cmid: (int) $cm->id,
            label: $this->plain_label($cm),
            visible: (bool) $cm->uservisible,
            available: $available,
            completion: $completionvalue,
            pass: $pass,
            grade: $gradepercent,
            deadline: $deadline,
            opensat: $opensat,
            lastactivity: $lastactivity,
            dataquality: in_array($cm->modname, $this->failedadapters, true) ? 'partial' : $quality,
            courseid: (int) $cm->course,
            modname: (string) $cm->modname,
            lastused: $lastused,
            closesat: $facts?->closesat,
            attemptsused: $facts?->attemptsused,
            attemptsallowed: $facts?->attemptsallowed,
            submitted: $submitted,
            lockreason: $lockreason,
        );
    }

    /**
     * Last use of each activity by the user, from the standard log store, in one query.
     *
     * Uses the index (userid, contextlevel, contextinstanceid, ...). When the standard log store is
     * not enabled the result is empty, so usage stays unknown.
     *
     * @param int $userid User id.
     * @param int[] $cmids Course module ids.
     * @return int[] Unix time keyed by course module id.
     */
    private function load_usage(int $userid, array $cmids): array {
        global $DB;

        if (!$cmids) {
            return [];
        }
        $enabled = explode(',', (string) get_config('tool_log', 'enabled_stores'));
        if (!in_array('logstore_standard', $enabled, true)) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED);
        $params += ['userid' => $userid, 'contextlevel' => CONTEXT_MODULE];
        try {
            return array_map('intval', $DB->get_records_sql_menu(
                "SELECT contextinstanceid, MAX(timecreated)
                   FROM {logstore_standard_log}
                  WHERE userid = :userid AND contextlevel = :contextlevel AND contextinstanceid $insql
               GROUP BY contextinstanceid",
                $params
            ));
        } catch (\dml_exception $e) {
            return [];
        }
    }

    /**
     * Load grade information for all activities of a course in one query.
     *
     * @param int $courseid Course id.
     * @param int $userid User id.
     * @return array Rows keyed by "modname:instance".
     */
    private function load_grades(int $courseid, int $userid): array {
        global $DB;

        $sql = "SELECT gi.id AS itemid, gi.itemmodule, gi.iteminstance, gi.grademax, gi.gradepass,
                       gi.hidden AS itemhidden, gg.finalgrade, gg.hidden AS gradehidden, gg.timemodified
                  FROM {grade_items} gi
             LEFT JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = :userid
                 WHERE gi.courseid = :courseid AND gi.itemtype = 'mod' AND gi.itemnumber = 0";
        $result = [];
        foreach ($DB->get_recordset_sql($sql, ['userid' => $userid, 'courseid' => $courseid]) as $row) {
            // A hidden item or a hidden grade is unknown for our purposes: never leak it.
            $row->hidden = !empty($row->itemhidden) || !empty($row->gradehidden);
            $result[$row->itemmodule . ':' . $row->iteminstance] = $row;
        }
        return $result;
    }

    /**
     * A plain text name for the activity.
     *
     * @param \cm_info $cm Activity.
     * @return string
     */
    private function plain_label(\cm_info $cm): string {
        return clean_param(format_string($cm->name, true, ['context' => $cm->context]), PARAM_NOTAGS);
    }
}
