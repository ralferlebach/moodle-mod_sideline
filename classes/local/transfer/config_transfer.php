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

namespace mod_sideline\local\transfer;

use mod_sideline\local\cache\commentary_cache;
use mod_sideline\local\dialog\dialog_manager;
use mod_sideline\local\instance_config;
use mod_sideline\local\rules\rule_schema;
use mod_sideline\local\rules\rule_store;

/**
 * Selective export and import of an instance configuration (P1-09).
 *
 * The export contains configuration only: settings, activity scope (by module type and name, since
 * ids differ between courses), custom rules and interview questions. It never contains personal
 * data: no answers, consents, caches, traces or group memberships.
 *
 * Import is two-step: preview() reports what would change, including conflicts and activities that
 * could not be mapped; apply() performs the selected parts. Older formats are migrated first.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class config_transfer {
    /** @var string Format identifier. */
    public const FORMAT = 'mod_sideline_config';
    /** @var int Current format version. */
    public const VERSION = 2;
    /** @var string[] Parts that can be imported selectively. */
    public const PARTS = ['settings', 'scope', 'rules', 'questions'];
    /** @var string[] Settings that are transferred. */
    public const SETTINGS = ['display', 'scopetype', 'scopesubsections', 'persona', 'personas', 'personachoice', 'typing',
        'aienabled', 'consentrequired', 'dialogenabled', 'dialogcooldown', 'sharingmode', 'grouppublish', 'groupminsize'];

    /**
     * Export an instance.
     *
     * @param \stdClass $sideline Instance record.
     * @return array
     */
    public static function export(\stdClass $sideline): array {
        $settings = [];
        foreach (self::SETTINGS as $name) {
            $settings[$name] = $sideline->$name;
        }
        $modinfo = get_fast_modinfo((int) $sideline->course);
        $activities = [];
        foreach (instance_config::get_scope_activities((int) $sideline->id) as $cmid) {
            $cm = $modinfo->get_cms()[$cmid] ?? null;
            if ($cm) {
                $activities[] = ['modname' => $cm->modname, 'name' => $cm->name];
            }
        }
        $section = $modinfo->get_section_info((int) $sideline->scopesection);
        $rules = [];
        foreach (rule_store::definitions((int) $sideline->id, false) as $rule) {
            $enabled = (int) $rule['enabled'];
            unset($rule['id'], $rule['enabled']);
            // Rule conditions refer to activities by id: export them by name as well.
            foreach ($rule['conditions'] as &$condition) {
                if (!empty($condition['cmid']) && isset($modinfo->get_cms()[$condition['cmid']])) {
                    $cm = $modinfo->get_cms()[$condition['cmid']];
                    $condition['activity'] = ['modname' => $cm->modname, 'name' => $cm->name];
                }
            }
            unset($condition);
            $rules[] = ['enabled' => $enabled, 'definition' => $rule];
        }
        $questions = [];
        foreach (dialog_manager::questions((int) $sideline->id, false) as $q) {
            $questions[] = [
                'qtype' => $q->qtype, 'prompt' => $q->prompt, 'config' => $q->configdata, 'triggerkey' => $q->triggerkey,
                'cooldown' => (int) $q->cooldown, 'enabled' => (int) $q->enabled,
            ];
        }
        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'settings' => $settings,
            'scope' => [
                'section' => $section ? (int) $section->section : 0,
                'activities' => $activities,
            ],
            'rules' => $rules,
            'questions' => $questions,
        ];
    }

    /**
     * Bring older formats to the current one.
     *
     * Version 1 had settings and scope only.
     *
     * @param mixed $data Decoded JSON.
     * @return array Migrated data.
     */
    public static function migrate(mixed $data): array {
        if (!is_array($data) || ($data['format'] ?? '') !== self::FORMAT) {
            throw new \moodle_exception('err_transfer_format', 'mod_sideline');
        }
        $version = (int) ($data['version'] ?? 0);
        if ($version < 1 || $version > self::VERSION) {
            throw new \moodle_exception('err_transfer_version', 'mod_sideline', '', $version);
        }
        if ($version === 1) {
            $data['rules'] = [];
            $data['questions'] = [];
            $data['version'] = 2;
        }
        foreach (['settings', 'scope', 'rules', 'questions'] as $part) {
            if (!isset($data[$part]) || !is_array($data[$part])) {
                $data[$part] = [];
            }
        }
        return $data;
    }

    /**
     * What an import would do.
     *
     * @param \stdClass $sideline Target instance.
     * @param array $data Migrated data.
     * @param string[] $parts Selected parts.
     * @param string $conflict replace, skip or rename for rules with an existing key.
     * @return array changes (string[]), warnings (string[]), plan (internal).
     */
    public static function preview(\stdClass $sideline, array $data, array $parts, string $conflict): array {
        $changes = [];
        $warnings = [];
        $plan = ['settings' => [], 'scope' => null, 'rules' => [], 'questions' => []];
        $modinfo = get_fast_modinfo((int) $sideline->course);

        if (in_array('settings', $parts, true)) {
            foreach (self::SETTINGS as $name) {
                if (array_key_exists($name, $data['settings']) && (string) $data['settings'][$name] !== (string) $sideline->$name) {
                    $plan['settings'][$name] = $data['settings'][$name];
                    $changes[] = get_string('transfer_change_setting', 'mod_sideline', $name);
                }
            }
        }
        if (in_array('scope', $parts, true)) {
            $cmids = [];
            foreach ((array) ($data['scope']['activities'] ?? []) as $activity) {
                $cmid = self::map_activity($modinfo, (array) $activity);
                if ($cmid) {
                    $cmids[] = $cmid;
                } else {
                    $warnings[] = get_string(
                        'transfer_unmapped',
                        'mod_sideline',
                        s(($activity['modname'] ?? '?') . ': ' . ($activity['name'] ?? '?')),
                    );
                }
            }
            $plan['scope'] = ['section' => (int) ($data['scope']['section'] ?? 0), 'cmids' => $cmids];
            $changes[] = get_string('transfer_change_scope', 'mod_sideline', count($cmids));
        }
        if (in_array('rules', $parts, true)) {
            $existing = array_column(rule_store::definitions((int) $sideline->id, false), null, 'key');
            $validcmids = array_map('intval', array_keys($modinfo->get_cms()));
            foreach ($data['rules'] as $item) {
                $definition = (array) ($item['definition'] ?? []);
                foreach ($definition['conditions'] ?? [] as $i => $condition) {
                    if (!empty($condition['cmid'])) {
                        $mapped = isset($condition['activity']) ? self::map_activity(
                            $modinfo,
                            (array) $condition['activity'],
                        ) : null;
                        $definition['conditions'][$i]['cmid'] = $mapped ?: -1;
                    }
                    unset($definition['conditions'][$i]['activity']);
                }
                $key = (string) ($definition['key'] ?? '');
                $action = 'add';
                if (isset($existing[$key])) {
                    if ($conflict === 'skip') {
                        $warnings[] = get_string('transfer_rule_skipped', 'mod_sideline', s($key));
                        continue;
                    }
                    if ($conflict === 'rename') {
                        $definition['key'] = self::free_key($key, array_keys($existing));
                    } else {
                        $action = 'replace';
                    }
                }
                [$clean, $errors] = rule_schema::validate($definition, $validcmids);
                if ($clean === null) {
                    $warnings[] = get_string('transfer_rule_invalid', 'mod_sideline', s($key . ': ' . implode(' ', $errors)));
                    continue;
                }
                $plan['rules'][] = ['action' => $action, 'id' => $action === 'replace' ? (int) $existing[$key]['id'] : 0,
                    'definition' => $clean, 'enabled' => !empty($item['enabled'])];
                $changes[] = get_string('transfer_change_rule_' . $action, 'mod_sideline', s($clean['key']));
            }
        }
        if (in_array('questions', $parts, true)) {
            foreach ($data['questions'] as $q) {
                $plan['questions'][] = (array) $q;
            }
            if ($plan['questions']) {
                $changes[] = get_string('transfer_change_questions', 'mod_sideline', count($plan['questions']));
            }
        }
        return ['changes' => $changes, 'warnings' => $warnings, 'plan' => $plan];
    }

    /**
     * Apply a previewed plan.
     *
     * @param \stdClass $sideline Target instance.
     * @param array $plan Plan from preview().
     */
    public static function apply(\stdClass $sideline, array $plan): void {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        if ($plan['settings']) {
            $record = clone $sideline;
            foreach ($plan['settings'] as $name => $value) {
                $record->$name = $value;
            }
            $record = instance_config::normalise($record);
            $update = (object) [
                'id' => $sideline->id,
                'configversion' => (int) $sideline->configversion + 1,
                'timemodified' => time(),
            ];
            foreach (self::SETTINGS as $name) {
                $update->$name = $record->$name;
            }
            // The same rules as the settings form: an import must not widen consent or sharing silently.
            instance_config::apply_version_bumps($sideline, $update);
            $DB->update_record('sideline', $update);
        }
        if ($plan['scope'] !== null) {
            $DB->set_field('sideline', 'scopesection', $plan['scope']['section'], ['id' => $sideline->id]);
            instance_config::save_scope_activities((int) $sideline->id, $plan['scope']['cmids']);
        }
        $fresh = $DB->get_record('sideline', ['id' => $sideline->id], '*', MUST_EXIST);
        foreach ($plan['rules'] as $rule) {
            rule_store::save($fresh, $rule['definition'], (int) $rule['id'], (bool) $rule['enabled']);
        }
        foreach ($plan['questions'] as $q) {
            dialog_manager::save_question((int) $sideline->id, (object) [
                'qtype' => (string) ($q['qtype'] ?? ''), 'prompt' => (string) ($q['prompt'] ?? ''),
                'config' => (array) ($q['config'] ?? []), 'triggerkey' => (string) ($q['triggerkey'] ?? 'any'),
                'cooldown' => (int) ($q['cooldown'] ?? 0), 'enabled' => (int) ($q['enabled'] ?? 1),
            ]);
        }
        commentary_cache::invalidate_instance((int) $sideline->id);
        $transaction->allow_commit();
        rebuild_course_cache((int) $sideline->course, true);
    }

    /**
     * Find an activity of the target course by module type and name.
     *
     * @param \course_modinfo $modinfo Target course.
     * @param array $activity modname and name.
     * @return int|null Course module id.
     */
    private static function map_activity(\course_modinfo $modinfo, array $activity): ?int {
        foreach ($modinfo->get_cms() as $cm) {
            if ($cm->modname === ($activity['modname'] ?? null) && $cm->name === ($activity['name'] ?? null)) {
                return (int) $cm->id;
            }
        }
        return null;
    }

    /**
     * A rule key that is not used yet.
     *
     * @param string $key Wanted key.
     * @param string[] $used Used keys.
     * @return string
     */
    private static function free_key(string $key, array $used): string {
        $base = substr($key, 0, 40);
        for ($i = 2; $i < 100; $i++) {
            $candidate = $base . '_' . $i;
            if (!in_array($candidate, $used, true)) {
                return $candidate;
            }
        }
        return $base . '_' . random_int(100, 999);
    }
}
