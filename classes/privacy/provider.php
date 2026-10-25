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

namespace mod_sideline\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\helper;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for mod_sideline (P-48, P1-10, P-G03).
 *
 * Covers every table that holds data about a person: generated commentary, consent and its log,
 * persona choice, interview answers, rule display times, group sharing decisions, decision traces
 * and the audit log of administrator debug exports (as subject and as acting administrator).
 * The learner's progress itself belongs to Moodle core and is neither exported nor deleted here.
 *
 * Group mode: another learner's cached commentary may contain a person's first name. When that
 * person's data is deleted in an instance that uses or used group sharing, all cached commentary
 * of the instance is deleted as well.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /** @var array Tables with sidelineid and userid, and their exported fields. */
    private const TABLES = [
        'sideline_cache' => ['commentary', 'source', 'timemodified'],
        'sideline_consent' => ['status', 'infoversion', 'timemodified'],
        'sideline_consent_log' => ['status', 'kind', 'infoversion', 'contextid', 'timecreated'],
        'sideline_pref' => ['persona', 'timemodified'],
        'sideline_answer' => ['questionid', 'value', 'skipped', 'timemodified'],
        'sideline_rulehit' => ['rulekey', 'timeshown'],
        'sideline_publish' => ['publish', 'named', 'infoversion', 'timemodified'],
        'sideline_trace' => ['trace', 'timecreated'],
        'sideline_debuglog' => ['adminid', 'purpose', 'timefrom', 'timeto', 'timecreated'],
    ];

    /**
     * Describe the data stored by this plugin.
     *
     * @param collection $collection The collection to add to.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        foreach (self::TABLES as $table => $fields) {
            $described = ['userid' => "privacy:metadata:{$table}:userid"];
            foreach ($fields as $field) {
                $described[$field] = "privacy:metadata:{$table}:{$field}";
            }
            $collection->add_database_table($table, $described, "privacy:metadata:{$table}");
        }
        $collection->add_subsystem_link('core_ai', [], 'privacy:metadata:ai');
        return $collection;
    }

    /**
     * Contexts that hold data about a user.
     *
     * @param int $userid User id.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $exists = [];
        $params = ['contextlevel' => CONTEXT_MODULE];
        $i = 0;
        foreach (array_keys(self::TABLES) as $table) {
            $i++;
            $exists[] = "EXISTS (SELECT 1 FROM {{$table}} t{$i} WHERE t{$i}.sidelineid = cm.instance AND t{$i}.userid = :u{$i})";
            $params['u' . $i] = $userid;
        }
        $exists[] = "EXISTS (SELECT 1 FROM {sideline_debuglog} d WHERE d.sidelineid = cm.instance AND d.adminid = :admin)";
        $params['admin'] = $userid;
        $sql = "SELECT DISTINCT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'sideline'
                 WHERE " . implode(' OR ', $exists);
        $contextlist->add_from_sql($sql, $params);
        return $contextlist;
    }

    /**
     * Users that have data in a context.
     *
     * @param userlist $userlist The userlist to add to.
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }
        $params = ['cmid' => $context->instanceid];
        $from = "FROM {course_modules} cm JOIN {modules} m ON m.id = cm.module AND m.name = 'sideline'";
        foreach (array_keys(self::TABLES) as $table) {
            $userlist->add_from_sql(
                'userid',
                "SELECT t.userid $from JOIN {{$table}} t ON t.sidelineid = cm.instance WHERE cm.id = :cmid",
                $params
            );
        }
        $userlist->add_from_sql(
            'adminid',
            "SELECT t.adminid $from JOIN {sideline_debuglog} t ON t.sidelineid = cm.instance WHERE cm.id = :cmid",
            $params
        );
    }

    /**
     * Export the data of a user.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $user = $contextlist->get_user();
        foreach ($contextlist->get_contexts() as $context) {
            $cm = self::cm($context);
            if (!$cm) {
                continue;
            }
            helper::export_context_files($context, $user);
            writer::with_context($context)->export_data([], helper::get_context_data($context, $user));

            foreach (self::TABLES as $table => $fields) {
                $rows = $DB->get_records($table, ['sidelineid' => $cm->instance, 'userid' => $user->id], 'id ASC');
                if ($rows) {
                    writer::with_context($context)->export_data(
                        [get_string('privacy:path:' . $table, 'mod_sideline')],
                        (object) ['items' => array_map(static fn($row) => self::export_row($row, $fields), array_values($rows))]
                    );
                }
            }
            $actions = $DB->get_records('sideline_debuglog', ['sidelineid' => $cm->instance, 'adminid' => $user->id], 'id ASC');
            if ($actions) {
                writer::with_context($context)->export_data(
                    [get_string('privacy:path:sideline_debuglog_actor', 'mod_sideline')],
                    (object) ['items' => array_map(static fn($row) => [
                        'purpose' => $row->purpose,
                        'time' => transform::datetime($row->timecreated),
                    ], array_values($actions))]
                );
            }
        }
    }

    /**
     * Delete all data of all users in a context.
     *
     * @param \context $context The context.
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        $cm = self::cm($context);
        if (!$cm) {
            return;
        }
        foreach (array_keys(self::TABLES) as $table) {
            $DB->delete_records($table, ['sidelineid' => $cm->instance]);
        }
    }

    /**
     * Delete the data of one user in the approved contexts.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            $cm = self::cm($context);
            if ($cm) {
                self::delete_users((int) $cm->instance, [$userid]);
            }
        }
    }

    /**
     * Delete the data of several users in one context.
     *
     * @param approved_userlist $userlist Approved users.
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        $cm = self::cm($userlist->get_context());
        $userids = array_map('intval', $userlist->get_userids());
        if ($cm && $userids) {
            self::delete_users((int) $cm->instance, $userids);
        }
    }

    /**
     * Delete users' rows in one instance.
     *
     * @param int $sidelineid Instance id.
     * @param int[] $userids Users.
     */
    private static function delete_users(int $sidelineid, array $userids): void {
        global $DB;

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        foreach (array_keys(self::TABLES) as $table) {
            $DB->delete_records_select($table, "sidelineid = :sid AND userid $insql", ['sid' => $sidelineid] + $inparams);
        }
        // The audit log keeps the fact that an export happened, not who did it.
        $DB->set_field_select(
            'sideline_debuglog',
            'adminid',
            0,
            "sidelineid = :sid AND adminid $insql",
            ['sid' => $sidelineid] + $inparams,
        );
        // Other learners' commentary may name these users when group mode is or was on.
        $mode = $DB->get_field('sideline', 'sharingmode', ['id' => $sidelineid]);
        if (
            $mode !== \mod_sideline\local\instance_config::GROUP_INDIVIDUAL
                || $DB->record_exists_select('sideline_publish', 'sidelineid = ?', [$sidelineid])
        ) {
            $DB->delete_records('sideline_cache', ['sidelineid' => $sidelineid]);
        }
    }

    /**
     * Readable export of a row.
     *
     * @param \stdClass $row Row.
     * @param string[] $fields Fields.
     * @return array
     */
    private static function export_row(\stdClass $row, array $fields): array {
        $out = [];
        foreach ($fields as $field) {
            $value = $row->$field;
            if (str_starts_with($field, 'time') && is_numeric($value)) {
                $value = (int) $value ? transform::datetime($value) : null;
            } else if (in_array($field, ['commentary', 'trace', 'value'], true) && is_string($value)) {
                $decoded = json_decode($value, true);
                $value = $decoded ?? $value;
            }
            $out[$field] = $value;
        }
        return $out;
    }

    /**
     * Course module of a module context of this plugin.
     *
     * @param \context $context Context.
     * @return \stdClass|null
     */
    private static function cm(\context $context): ?\stdClass {
        if (!$context instanceof \context_module) {
            return null;
        }
        return get_coursemodule_from_id('sideline', $context->instanceid) ?: null;
    }
}
