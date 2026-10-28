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

namespace mod_sideline\local\rules;

use mod_sideline\local\cache\commentary_cache;

/**
 * Persistence of custom rules and of the times a rule was shown (cooldowns, frequency limits).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rule_store {
    /**
     * Enabled custom rule definitions of an instance, in order.
     *
     * @param int $sidelineid Instance id.
     * @param bool $enabledonly Only enabled ones.
     * @return array[] Definitions with an extra "id" and "enabled".
     */
    public static function definitions(int $sidelineid, bool $enabledonly = true): array {
        global $DB;

        $params = ['sidelineid' => $sidelineid];
        if ($enabledonly) {
            $params['enabled'] = 1;
        }
        $result = [];
        foreach ($DB->get_records('sideline_rule', $params, 'sortorder, id') as $row) {
            $definition = json_decode($row->definition, true);
            if (is_array($definition)) {
                $definition['id'] = (int) $row->id;
                $definition['enabled'] = (int) $row->enabled;
                $result[] = $definition;
            }
        }
        return $result;
    }

    /**
     * Save a validated definition.
     *
     * @param \stdClass $sideline Instance record.
     * @param array $definition Raw definition.
     * @param int $id Existing rule id or 0.
     * @param bool $enabled Whether enabled.
     * @return int Rule id.
     */
    public static function save(\stdClass $sideline, array $definition, int $id = 0, bool $enabled = true): int {
        global $DB;

        $cmids = array_map('intval', array_keys(get_fast_modinfo((int) $sideline->course)->get_cms()));
        [$clean, $errors] = rule_schema::validate($definition, $cmids);
        if ($clean === null) {
            throw new \invalid_parameter_exception(implode(' ', $errors));
        }
        $existing = $DB->get_record('sideline_rule', ['sidelineid' => $sideline->id, 'rulekey' => $clean['key']]);
        if ($existing && (int) $existing->id !== $id) {
            throw new \invalid_parameter_exception(get_string('err_rule_keyexists', 'mod_sideline'));
        }
        if (!$id && $DB->count_records('sideline_rule', ['sidelineid' => $sideline->id]) >= rule_schema::MAX_RULES) {
            throw new \invalid_parameter_exception(get_string('err_rule_max', 'mod_sideline', rule_schema::MAX_RULES));
        }
        // Template version per rule (P-07): a new rule starts at 1, a changed wording raises it.
        $previous = $id ? $DB->get_field('sideline_rule', 'definition', ['id' => $id, 'sidelineid' => $sideline->id]) : false;
        $previous = $previous ? (array) json_decode($previous, true) : [];
        $clean['templateversion'] = max(1, (int) ($previous['templateversion'] ?? 1));
        if ($previous && ($previous['templates'] ?? null) != $clean['templates']) {
            $clean['templateversion']++;
        }
        $record = (object) [
            'sidelineid' => $sideline->id,
            'rulekey' => $clean['key'],
            'definition' => json_encode($clean, JSON_UNESCAPED_UNICODE),
            'enabled' => $enabled ? 1 : 0,
            'timemodified' => time(),
        ];
        if ($id) {
            $DB->get_record('sideline_rule', ['id' => $id, 'sidelineid' => $sideline->id], 'id', MUST_EXIST);
            $record->id = $id;
            $DB->update_record('sideline_rule', $record);
        } else {
            $record->sortorder = (int) $DB->count_records('sideline_rule', ['sidelineid' => $sideline->id]) + 1;
            $record->timecreated = $record->timemodified;
            $id = (int) $DB->insert_record('sideline_rule', $record);
        }
        self::changed($sideline);
        return $id;
    }

    /**
     * Delete a rule.
     *
     * @param \stdClass $sideline Instance record.
     * @param int $id Rule id.
     */
    public static function delete(\stdClass $sideline, int $id): void {
        global $DB;

        $DB->delete_records('sideline_rule', ['id' => $id, 'sidelineid' => $sideline->id]);
        self::changed($sideline);
    }

    /**
     * Times each rule key was shown to the learner within the last week.
     *
     * @param int $sidelineid Instance id.
     * @param int $userid User id.
     * @param int $now Current time.
     * @return array Rule key to list of unix times, newest first.
     */
    public static function hits(int $sidelineid, int $userid, int $now): array {
        global $DB;

        $result = [];
        $rows = $DB->get_records_select(
            'sideline_rulehit',
            'sidelineid = ? AND userid = ? AND timeshown > ?',
            [$sidelineid, $userid, $now - 365 * DAYSECS],
            'timeshown DESC',
            'id, rulekey, timeshown'
        );
        foreach ($rows as $row) {
            $result[$row->rulekey][] = (int) $row->timeshown;
        }
        return $result;
    }

    /**
     * Record that rule keys newly appeared in the learner's commentary.
     *
     * @param int $sidelineid Instance id.
     * @param int $userid User id.
     * @param string[] $keys Rule keys.
     * @param int $now Current time.
     */
    public static function record_hits(int $sidelineid, int $userid, array $keys, int $now): void {
        global $DB;

        foreach (array_unique($keys) as $key) {
            $DB->insert_record('sideline_rulehit', (object) [
                'sidelineid' => $sidelineid, 'userid' => $userid, 'rulekey' => $key, 'timeshown' => $now,
            ]);
        }
    }

    /**
     * After a rule change: bump the configuration version, so every cached text becomes stale.
     *
     * @param \stdClass $sideline Instance record.
     */
    private static function changed(\stdClass $sideline): void {
        global $DB;

        $DB->set_field(
            'sideline',
            'configversion',
            (int) $DB->get_field('sideline', 'configversion', ['id' => $sideline->id]) + 1,
            ['id' => $sideline->id]
        );
        commentary_cache::invalidate_instance((int) $sideline->id);
    }
}
