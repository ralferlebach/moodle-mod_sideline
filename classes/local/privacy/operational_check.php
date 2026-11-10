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

namespace mod_sideline\local\privacy;

use mod_sideline\local\ai\ai_records;
use mod_sideline\local\group\group_service;

/**
 * Operational privacy check of a site: what the plugin keeps, for how long, and whether retention works.
 *
 * Run by an administrator on the target system (cli/privacy_check.php), as part of the documented deletion and
 * access process (docs/privacy/DATENSCHUTZ.md, section 7). Every check gives ok, warn or fail with the numbers.
 * Its output holds counts, settings and paths, never content. To recognise entries of 0.4.1 without an instance
 * it reads the prompts of AI entries made before the upgrade to 0.4.3 (ai_records::unattributed()).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class operational_check {
    /** @var string Result: as intended. */
    public const OK = 'ok';
    /** @var string Result: allowed, but needs a look or a decision. */
    public const WARN = 'warn';
    /** @var string Result: retention or deletion does not work as intended. */
    public const FAIL = 'fail';

    /** @var int Days without a run of the retention task after which its deletions are overdue. */
    private const TASK_LATE_DAYS = 2;

    /**
     * Run every check.
     *
     * @param int $now Current time.
     * @return array[] List of [name, result, detail].
     */
    public static function run(int $now): array {
        return [
            self::retention_settings(),
            self::ai_entries($now),
            self::unattributed_ai_entries(),
            self::ai_providers(),
            self::day_counts($now),
            self::caches(),
            self::removed_files(),
            self::traces($now),
            self::debug_exports($now),
        ];
    }

    /**
     * The retention settings, as configured.
     *
     * @return array
     */
    private static function retention_settings(): array {
        $values = [];
        foreach (
            ['traceretentiondays', 'auditretentiondays', 'consentlogretentiondays', 'answerretentiondays',
            'cacheretentiondays', 'airetentiondays'] as $name
        ) {
            $values[] = $name . '=' . (int) get_config('mod_sideline', $name);
        }
        $ai = (int) get_config('mod_sideline', 'airetentiondays');
        return ['retention', $ai > 0 ? self::OK : self::WARN, implode(', ', $values)
            . ($ai > 0 ? '' : '; prompts and answers in the AI subsystem are kept as long as the subsystem keeps them')];
    }

    /**
     * Entries of the plugin in the AI subsystem: how many, how old, and none older than the retention.
     *
     * @param int $now Current time.
     * @return array
     */
    private static function ai_entries(int $now): array {
        global $DB;

        $count = $DB->count_records('sideline_aicall');
        $oldest = (int) $DB->get_field_sql('SELECT MIN(timecreated) FROM {sideline_aicall}');
        $detail = "$count entries of this plugin" . ($count ? ', oldest ' . (int) floor(($now - $oldest) / DAYSECS) . ' days' : '');
        $days = (int) get_config('mod_sideline', 'airetentiondays');
        if ($days <= 0) {
            return ['ai entries', self::OK, $detail . '; no retention set'];
        }
        $overdue = $DB->count_records_select(
            'sideline_aicall',
            'timecreated < ?',
            [$now - ($days + self::TASK_LATE_DAYS) * DAYSECS]
        );
        return ['ai entries', $overdue ? self::FAIL : self::OK,
            $detail . ($overdue ? "; $overdue older than the retention of $days days: the scheduled task does not run" : '')];
    }

    /**
     * Entries of 0.4.1 that belong to no instance and that only the administrator can empty.
     *
     * @return array
     */
    private static function unattributed_ai_entries(): array {
        $count = count(ai_records::unattributed());
        return ['ai entries without instance', $count ? self::WARN : self::OK, $count
            ? "$count entries of 0.4.1 in users' contexts; a withdrawal cannot reach them; empty them with --empty-unattributed"
            : 'none'];
    }

    /**
     * Enabled AI providers: what leaves the site, and to whom (their retention is outside Moodle).
     *
     * @return array
     */
    private static function ai_providers(): array {
        $ai = (bool) get_config('mod_sideline', 'aienabled');
        $manager = \core\di::get(\core_ai\manager::class);
        if (method_exists($manager, 'get_provider_instances')) {
            // Moodle 5.0 and later: provider instances, each with its own configuration.
            $enabled = array_map(
                static fn($provider): string => $provider->name . ' (' . $provider->provider . ')',
                $manager->get_provider_instances(['enabled' => 1])
            );
        } else {
            $enabled = array_keys(\core_plugin_manager::instance()->get_enabled_plugins('aiprovider') ?: []);
        }
        if (!$ai || !$enabled) {
            return ['ai providers', self::OK, $ai ? 'no provider enabled: nothing leaves the site' : 'AI is off for the plugin'];
        }
        return ['ai providers', self::WARN, 'enabled: ' . implode(', ', $enabled)
            . '; their retention is outside Moodle and must be covered by the processing agreement'];
    }

    /**
     * Day counts of group statements: only the current day may exist (after the scheduled task).
     *
     * @param int $now Current time.
     * @return array
     */
    private static function day_counts(int $now): array {
        global $DB;

        $old = $DB->count_records_select(
            'sideline_daycount',
            'daystart < ?',
            [group_service::day_start($now) - self::TASK_LATE_DAYS * DAYSECS]
        );
        return ['group day counts', $old ? self::FAIL : self::OK, $old ? "$old rows of earlier days" : 'only recent days'];
    }

    /**
     * No cache definition of 0.4.1 any more, and nothing left of its files in any file store: the default ones and
     * those with a path of their own in the cache configuration, as the upgrade cleans them.
     *
     * @return array
     */
    private static function caches(): array {
        global $CFG;

        $definitions = array_filter(
            array_keys(\cache_config::instance()->get_definitions()),
            static fn(string $id): bool => $id === 'mod_sideline/groupsnapshot'
        );
        $roots = [$CFG->cachedir . '/cachestore_file', $CFG->localcachedir . '/cachestore_file',
            $CFG->dataroot . '/cache/cachestore_file'];
        foreach (\cache_config::instance()->get_all_stores() as $store) {
            if (($store['plugin'] ?? '') === 'file' && !empty($store['configuration']['path'])) {
                $roots[] = (string) $store['configuration']['path'];
            }
        }
        $left = [];
        foreach (array_unique($roots) as $root) {
            $left = array_merge(
                $left,
                glob($root . '/mod_sideline_groupsnapshot', GLOB_ONLYDIR) ?: [],
                glob($root . '/*/mod_sideline_groupsnapshot', GLOB_ONLYDIR) ?: []
            );
        }
        if ($definitions || $left) {
            return ['caches', self::FAIL, trim(implode(', ', $definitions) . ' ' . implode(', ', array_unique($left)))];
        }
        return ['caches', self::OK, 'the plugin keeps nothing about people in caches'];
    }

    /**
     * Files removed by a release must be gone (a release copied over an older one keeps them).
     *
     * @return array
     */
    private static function removed_files(): array {
        global $CFG;

        $present = self::removed_files_in($CFG->dirroot . '/mod/sideline');
        return ['removed files', $present ? self::FAIL : self::OK, $present ? implode(', ', $present) : 'none present'];
    }

    /**
     * The paths of db/removed_files.txt that exist in a plugin directory.
     *
     * @param string $base Plugin directory.
     * @return string[] Paths relative to it.
     */
    public static function removed_files_in(string $base): array {
        $list = $base . '/db/removed_files.txt';
        if (!is_readable($list)) {
            return [];
        }
        $present = [];
        foreach (file($list, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $path = trim($line);
            if ($path !== '' && !str_starts_with($path, '#') && !str_contains($path, '..') && file_exists($base . '/' . $path)) {
                $present[] = $path;
            }
        }
        return $present;
    }

    /**
     * Decision traces: kept no longer than set, tracing not left on.
     *
     * @param int $now Current time.
     * @return array
     */
    private static function traces(int $now): array {
        global $DB;

        $days = max(1, (int) (get_config('mod_sideline', 'traceretentiondays') ?: 14));
        $overdue = $DB->count_records_select(
            'sideline_trace',
            'timecreated < ?',
            [$now - ($days + self::TASK_LATE_DAYS) * DAYSECS]
        );
        if ($overdue) {
            return ['traces', self::FAIL, "$overdue traces older than $days days: the scheduled task does not run"];
        }
        $on = (bool) get_config('mod_sideline', 'tracing');
        return ['traces', $on ? self::WARN : self::OK, $DB->count_records('sideline_trace') . ' traces'
            . ($on ? '; tracing is on until ' . userdate((int) get_config('mod_sideline', 'tracinguntil')) : '')];
    }

    /**
     * Debug exports: only administrators, every one audited.
     *
     * @param int $now Current time.
     * @return array
     */
    private static function debug_exports(int $now): array {
        global $DB;

        $recent = $DB->count_records_select('sideline_debuglog', 'timecreated >= ?', [$now - 30 * DAYSECS]);
        $admins = array_map('intval', explode(',', (string) get_config('core', 'siteadmins')));
        $others = $DB->count_records_select(
            'sideline_debuglog',
            'adminid NOT IN (' . implode(',', $admins ?: [0]) . ')'
        );
        return ['debug exports', $others ? self::WARN : self::OK, "$recent exports in the last 30 days"
            . ($others ? "; $others by people who are no site administrators now" : '; all by site administrators')];
    }
}
