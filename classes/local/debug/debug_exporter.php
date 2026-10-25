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

use mod_sideline\local\consent\consent_manager;
use mod_sideline\local\dialog\dialog_manager;
use mod_sideline\local\group\sharing_manager;
use mod_sideline\local\service\commentary_service;

/**
 * Administrator-only debug export (P1-10).
 *
 * Purpose: explain to a site administrator why a learner got a certain commentary, for support or
 * a data protection request. It contains the inputs, the rule decision (all candidates and the
 * selection), the output, the learner's answers with their effects, the consent and sharing
 * status, and the decision traces within the chosen time window. It is computed live without
 * writing anything and without calling AI. Every export is written to the audit log with the
 * administrator, the purpose and the scope. There is no teacher access.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class debug_exporter {
    /**
     * Build an export and log it.
     *
     * @param int $cmid Course module of the instance.
     * @param int $userid Learner.
     * @param string $purpose Purpose stated by the administrator.
     * @param int $from Start of the trace window.
     * @param int $to End of the trace window.
     * @param int $adminid Administrator.
     * @return array
     */
    public static function export(int $cmid, int $userid, string $purpose, int $from, int $to, int $adminid): array {
        global $DB;

        if (!is_siteadmin($adminid)) {
            throw new \required_capability_exception(\context_system::instance(), 'moodle/site:config', 'nopermissions', '');
        }
        $purpose = trim(clean_param($purpose, PARAM_TEXT));
        if (mb_strlen($purpose) < 10) {
            throw new \invalid_parameter_exception(get_string('err_debugpurpose', 'mod_sideline'));
        }
        [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'sideline');
        $sideline = $DB->get_record('sideline', ['id' => $cm->instance], '*', MUST_EXIST);
        $user = \core_user::get_user($userid, '*', MUST_EXIST);
        $context = \context_module::instance($cm->id);

        $DB->insert_record('sideline_debuglog', (object) [
            'adminid' => $adminid,
            'sidelineid' => $sideline->id,
            'userid' => $userid,
            'purpose' => $purpose,
            'timefrom' => $from,
            'timeto' => $to,
            'timecreated' => time(),
        ]);

        // Compute as the learner would see it now: their language and their rights.
        $result = (new commentary_service())->get($sideline, $course, $user, $context, time(), true);

        $traces = [];
        $rows = $DB->get_records_select(
            'sideline_trace',
            'sidelineid = ? AND userid = ? AND timecreated >= ? AND timecreated <= ?',
            [$sideline->id, $userid, $from, $to],
            'timecreated'
        );
        foreach ($rows as $row) {
            $traces[] = ['time' => (int) $row->timecreated, 'trace' => json_decode($row->trace, true)];
        }

        return [
            'export' => [
                'purpose' => $purpose,
                'admin' => $adminid,
                'created' => time(),
                'window' => [$from, $to],
            ],
            'instance' => [
                'id' => (int) $sideline->id,
                'cmid' => (int) $cm->id,
                'course' => (int) $course->id,
                'configversion' => (int) $sideline->configversion,
                'sharingmode' => $sideline->sharingmode,
                'aienabled' => (int) $sideline->aienabled,
            ],
            'learner' => ['id' => $userid],
            'consent' => consent_manager::status($sideline, $userid),
            'sharing' => sharing_manager::status($sideline, $userid),
            'answers' => dialog_manager::my_answers($sideline, $userid),
            'inputs_and_decision' => $result['debug'] ?? null,
            'output' => $result['commentary'],
            'question_due' => $result['question'],
            'cache' => $DB->get_record(
                'sideline_cache',
                ['sidelineid' => $sideline->id, 'userid' => $userid],
                'fingerprint, source, dirty, stamp, nexttransition, timemodified'
            ) ?: null,
            'traces' => $traces,
        ];
    }
}
