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

/**
 * Decision traces for administrators (P1-10).
 *
 * Traces are written only while a site administrator has enabled tracing, and only until the
 * configured end time. They hold the inputs, the rule decision and the output of one generation,
 * never AI prompts with real values. They are personal data: covered by the privacy provider and
 * deleted after the retention period by the scheduled task.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class trace_recorder {
    /**
     * Whether tracing is active now.
     *
     * @param int $now Current time.
     * @return bool
     */
    public static function active(int $now): bool {
        if (!get_config('mod_sideline', 'tracing')) {
            return false;
        }
        $until = (int) get_config('mod_sideline', 'tracinguntil');
        return $until === 0 || $now < $until;
    }

    /**
     * Record a trace if tracing is active.
     *
     * @param int $sidelineid Instance id.
     * @param int $userid User id.
     * @param array $trace Trace data.
     * @param int $now Current time.
     */
    public static function record(int $sidelineid, int $userid, array $trace, int $now): void {
        global $DB;

        if (!self::active($now)) {
            return;
        }
        $DB->insert_record('sideline_trace', (object) [
            'sidelineid' => $sidelineid,
            'userid' => $userid,
            'trace' => json_encode($trace, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'timecreated' => $now,
        ]);
    }
}
