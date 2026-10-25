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

namespace mod_sideline\task;

use mod_sideline\local\cache\commentary_cache;

/**
 * Retention: deletes cached commentary, decision traces, rule display times, audit log entries and
 * (when configured) consent log entries after their retention periods (P1-10, P0-02).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class purge_cache extends \core\task\scheduled_task {
    /** @var int Default retention of cached commentary in days. */
    public const DEFAULT_RETENTION_DAYS = 90;

    #[\Override]
    public function get_name() {
        return get_string('task_purge_cache', 'mod_sideline');
    }

    #[\Override]
    public function execute() {
        $days = (int) get_config('mod_sideline', 'cacheretentiondays');
        if ($days <= 0) {
            $days = self::DEFAULT_RETENTION_DAYS;
        }
        global $DB;

        $now = time();
        $deleted = commentary_cache::purge_older_than($now - $days * DAYSECS);
        mtrace("mod_sideline: deleted $deleted cached commentaries older than $days days.");

        $tracedays = max(1, (int) (get_config('mod_sideline', 'traceretentiondays') ?: 14));
        $DB->delete_records_select('sideline_trace', 'timecreated < ?', [$now - $tracedays * DAYSECS]);
        if (
            !get_config('mod_sideline', 'tracing') || ((int) get_config('mod_sideline', 'tracinguntil')
                && $now >= (int) get_config('mod_sideline', 'tracinguntil'))
        ) {
            set_config('tracing', 0, 'mod_sideline');
        }

        $auditdays = max(30, (int) (get_config('mod_sideline', 'auditretentiondays') ?: 365));
        $DB->delete_records_select('sideline_debuglog', 'timecreated < ?', [$now - $auditdays * DAYSECS]);

        $DB->delete_records_select('sideline_rulehit', 'timeshown < ?', [$now - 365 * DAYSECS]);

        // Interview answers are kept for the configured period after their last change (P-17), 0 keeps them.
        $answerdays = (int) get_config('mod_sideline', 'answerretentiondays');
        if ($answerdays > 0) {
            $DB->delete_records_select('sideline_answer', 'timemodified < ?', [$now - $answerdays * DAYSECS]);
        }

        // Consent evidence is kept unless the site sets a retention (legal basis decides, P0-02).
        $consentdays = (int) get_config('mod_sideline', 'consentlogretentiondays');
        if ($consentdays > 0) {
            $DB->delete_records_select('sideline_consent_log', 'timecreated < ?', [$now - $consentdays * DAYSECS]);
        }
    }
}
