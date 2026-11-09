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

use mod_sideline\local\ai\persona_registry;
use mod_sideline\local\cache\commentary_cache;
use mod_sideline\local\dialog\dialog_manager;
use mod_sideline\local\group\day_count;
use mod_sideline\local\instance_config;
use mod_sideline\local\persona\persona_manager;
use mod_sideline\local\rules\rule_schema;
use mod_sideline\local\rules\rule_store;

/**
 * Selective export and import of an instance configuration (P1-09, L-12, P-33, P-34).
 *
 * The export contains configuration only: settings, activity scope, custom rules, interview
 * questions, the further courses and course branches of a course union, the admitted groups and
 * groupings, and the personas (offered keys and the definitions of site personas). Activities,
 * courses, categories and groups are referred to by name or id number, never by database id. It
 * never contains personal data: no answers, consents, consent logs, caches, traces, debug logs,
 * tokens or group memberships.
 *
 * Import is two-step: preview() reports what would change, including conflicts and references
 * that could not be mapped; apply() performs the selected parts. Older formats are migrated first.
 *
 * The import is a permission and consent boundary. It enforces the same rights as the
 * interactive forms: a course or course branch is only added if the importing teacher may manage
 * activities there, a group only if it exists in the target course, a persona definition only for
 * site administrators and only as a draft that needs approval. A malformed file, or one that
 * exceeds a limit (size, nesting, number of entries per part, length of a text, the limits of an
 * instance), is rejected as a whole: nothing of it is imported and nothing is cut off silently.
 * Group sharing is never switched on with an implicit "all groups": unless the admitted
 * groups are transferred and resolved, or the target already restricts them, sharing stays off.
 * Every change runs through the same version rules as the form, so widening consent or sharing
 * asks learners again.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class config_transfer {
    /** @var int Largest file that is read at all (the upload form has the same limit). */
    public const MAX_BYTES = 512 * 1024;

    /** @var \WeakMap|null Activities of a course structure by type and name (map_activity()). */
    private static ?\WeakMap $activityindex = null;

    /** @var int Deepest nesting of the JSON; an export of this plugin needs less than half of it. */
    public const MAX_DEPTH = 16;

    /** @var int Longest text of a single value. */
    public const MAX_STRING = 10000;

    /** @var int[] Most entries per part of the file. */
    public const LIMITS = [
        'scope.activities' => 1000,
        'rules' => rule_schema::MAX_RULES,
        'questions' => 100,
        'personas' => 50,
        'sources.courses' => instance_config::MAX_SOURCES,
        'sources.categories' => instance_config::MAX_SOURCE_CATEGORIES,
        'groups.items' => 1000,
    ];

    /** @var string Format identifier. */
    public const FORMAT = 'mod_sideline_config';
    /** @var int Current format version. */
    public const VERSION = 3;
    /** @var string[] Parts that can be imported selectively. */
    public const PARTS = ['settings', 'scope', 'rules', 'questions', 'sources', 'groups', 'personas'];
    /** @var string[] Settings that are transferred. */
    public const SETTINGS = ['display', 'scopetype', 'scopesubsections', 'persona', 'personas', 'personachoice', 'typing',
        'aienabled', 'consentrequired', 'dialogenabled', 'dialogcooldown', 'sharingmode', 'grouppublish', 'groupminsize',
        'groupevents'];

    /**
     * Export an instance.
     *
     * @param \stdClass $sideline Instance record.
     * @return array
     */
    public static function export(\stdClass $sideline): array {
        global $DB;

        $settings = [];
        foreach (self::SETTINGS as $name) {
            $settings[$name] = $sideline->$name ?? null;
        }
        $modinfo = get_fast_modinfo((int) $sideline->course);
        $activities = [];
        foreach (instance_config::get_scope_activities((int) $sideline->id) as $cmid) {
            $cm = $modinfo->get_cms()[$cmid] ?? null;
            if ($cm) {
                $activities[] = self::activity_ref($cm);
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
                    $condition['activity'] = self::activity_ref($modinfo->get_cms()[$condition['cmid']]);
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
        $courses = [];
        foreach (instance_config::get_sources((int) $sideline->id) as $courseid) {
            if ($course = $DB->get_record('course', ['id' => $courseid], 'id, shortname, idnumber')) {
                $courses[] = ['shortname' => $course->shortname, 'idnumber' => $course->idnumber];
            }
        }
        $categories = [];
        foreach (instance_config::get_source_categories((int) $sideline->id) as $categoryid) {
            if ($category = \core_course_category::get($categoryid, IGNORE_MISSING, true)) {
                $categories[] = ['idnumber' => (string) $category->idnumber, 'path' => self::category_names($category)];
            }
        }
        [$groupids, $groupingids] = instance_config::get_groups((int) $sideline->id);
        $groups = [];
        foreach ($groupids as $gid) {
            if ($group = $DB->get_record('groups', ['id' => $gid], 'id, name, idnumber')) {
                $groups[] = ['type' => 'group', 'name' => $group->name, 'idnumber' => $group->idnumber];
            }
        }
        foreach ($groupingids as $gid) {
            if ($grouping = $DB->get_record('groupings', ['id' => $gid], 'id, name, idnumber')) {
                $groups[] = ['type' => 'grouping', 'name' => $grouping->name, 'idnumber' => $grouping->idnumber];
            }
        }
        $personas = [];
        foreach (instance_config::offered_personas($sideline) as $key) {
            // Site personas are offered as "site_<shortname>".
            $short = str_starts_with($key, 'site_') ? substr($key, 5) : $key;
            $row = $DB->get_record('sideline_persona', ['shortname' => $short], 'shortname, name, style, opener');
            $personas[] = $row ? ['key' => $key, 'definition' => (array) $row] : ['key' => $key];
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
            'sources' => ['courses' => $courses, 'categories' => $categories],
            // The flag all says explicitly that every group of the course was admitted (no restriction).
            'groups' => ['all' => !$groups, 'items' => $groups],
            'personas' => $personas,
        ];
    }

    /**
     * Read an uploaded file: size and nesting limits, valid JSON, then migrate().
     *
     * @param string $content File content.
     * @return array Migrated data.
     */
    public static function decode(string $content): array {
        if (strlen($content) > self::MAX_BYTES) {
            self::limit('file', display_size(self::MAX_BYTES));
        }
        try {
            $data = json_decode($content, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            if ($e->getCode() === JSON_ERROR_DEPTH) {
                self::limit('depth', (string) self::MAX_DEPTH);
            }
            throw new \moodle_exception('err_transfer_json', 'mod_sideline');
        }
        return self::migrate($data);
    }

    /**
     * Reject a file that exceeds a limit.
     *
     * @param string $part Part of the file.
     * @param string $limit The limit, for display.
     * @return never
     */
    private static function limit(string $part, string $limit): never {
        throw new \moodle_exception('err_transfer_limit', 'mod_sideline', '', (object) ['part' => s($part), 'limit' => s($limit)]);
    }

    /**
     * Bring older formats to the current one and reject malformed files as a whole.
     *
     * Version 1 had settings and scope only, version 2 added rules and questions.
     *
     * @param mixed $data Decoded JSON.
     * @return array Migrated data.
     */
    public static function migrate(mixed $data): array {
        if (!is_array($data) || ($data['format'] ?? '') !== self::FORMAT) {
            throw new \moodle_exception('err_transfer_format', 'mod_sideline');
        }
        $version = $data['version'] ?? 0;
        if (!is_int($version) || $version < 1 || $version > self::VERSION) {
            throw new \moodle_exception('err_transfer_version', 'mod_sideline', '', s((string) json_encode($version)));
        }
        if ($version < 2) {
            $data['rules'] = [];
            $data['questions'] = [];
        }
        if ($version < 3) {
            $data['sources'] = ['courses' => [], 'categories' => []];
            // Older exports did not carry the groups: nothing is known, so nothing is admitted implicitly.
            $data['groups'] = ['all' => false, 'items' => [], 'unknown' => true];
            $data['personas'] = [];
        }
        $data['version'] = self::VERSION;
        foreach (['settings', 'scope', 'rules', 'questions', 'sources', 'groups', 'personas'] as $part) {
            if (!isset($data[$part])) {
                $data[$part] = [];
            }
        }
        self::check_structure($data);
        return $data;
    }

    /**
     * Reject a file whose parts do not have the expected shape.
     *
     * @param array $data Migrated data.
     */
    private static function check_structure(array $data): void {
        $fail = static function (string $where): void {
            throw new \moodle_exception('err_transfer_structure', 'mod_sideline', '', s($where));
        };
        foreach (['settings', 'scope', 'sources', 'groups'] as $part) {
            if (!is_array($data[$part]) || ($data[$part] && array_is_list($data[$part]))) {
                $fail($part);
            }
        }
        foreach (['rules', 'questions', 'personas'] as $part) {
            if (!is_array($data[$part]) || !array_is_list($data[$part])) {
                $fail($part);
            }
        }
        foreach ($data['settings'] as $name => $value) {
            if (!in_array($name, self::SETTINGS, true) || !(is_scalar($value) || $value === null)) {
                $fail('settings.' . $name);
            }
        }
        $lists = [
            'scope.activities' => $data['scope']['activities'] ?? [],
            'sources.courses' => $data['sources']['courses'] ?? [],
            'sources.categories' => $data['sources']['categories'] ?? [],
            'groups.items' => $data['groups']['items'] ?? [],
        ];
        foreach ($lists as $where => $list) {
            if (!is_array($list) || !array_is_list($list)) {
                $fail($where);
            }
            foreach ($list as $item) {
                if (!is_array($item)) {
                    $fail($where);
                }
                foreach ($item as $value) {
                    if (!(is_scalar($value) || $value === null || (is_array($value) && array_is_list($value)))) {
                        $fail($where);
                    }
                }
            }
        }
        foreach (array_merge($data['rules'], $data['questions'], $data['personas']) as $item) {
            if (!is_array($item)) {
                $fail('items');
            }
        }
        $counts = $lists + ['rules' => $data['rules'], 'questions' => $data['questions'], 'personas' => $data['personas']];
        foreach (self::LIMITS as $where => $max) {
            if (count($counts[$where]) > $max) {
                self::limit($where, (string) $max);
            }
        }
        array_walk_recursive($data, static function ($value, $key): void {
            if ((is_string($value) && mb_strlen($value) > self::MAX_STRING) || (is_string($key) && mb_strlen($key) > 100)) {
                self::limit('text', (string) self::MAX_STRING);
            }
        });
    }

    /**
     * What an import would do.
     *
     * @param \stdClass $sideline Target instance.
     * @param array $data Migrated data.
     * @param string[] $parts Selected parts.
     * @param string $conflict replace, skip or rename for rules with an existing key.
     * @param int|null $userid Importing user, defaults to the current user.
     * @return array changes (string[]), warnings (string[]), plan (internal).
     */
    public static function preview(\stdClass $sideline, array $data, array $parts, string $conflict, ?int $userid = null): array {
        global $USER;

        $userid = $userid ?? (int) $USER->id;
        $changes = [];
        $warnings = [];
        $plan = ['settings' => [], 'scope' => null, 'rules' => [], 'questions' => [], 'sources' => null, 'groups' => null,
            'personas' => [], 'userid' => $userid];
        $modinfo = get_fast_modinfo((int) $sideline->course);

        if (in_array('settings', $parts, true)) {
            foreach (self::SETTINGS as $name) {
                $current = (string) ($sideline->$name ?? '');
                if (array_key_exists($name, $data['settings']) && (string) $data['settings'][$name] !== $current) {
                    $plan['settings'][$name] = $data['settings'][$name];
                    $changes[] = get_string('transfer_change_setting', 'mod_sideline', $name);
                }
            }
        }
        if (in_array('scope', $parts, true)) {
            $cmids = [];
            foreach ((array) ($data['scope']['activities'] ?? []) as $activity) {
                [$cmid, $problem] = self::map_activity($modinfo, (array) $activity);
                if ($cmid) {
                    $cmids[] = $cmid;
                } else {
                    $warnings[] = get_string('transfer_' . $problem, 'mod_sideline', self::activity_label((array) $activity));
                }
            }
            $plan['scope'] = ['section' => (int) ($data['scope']['section'] ?? 0), 'cmids' => array_values(array_unique($cmids))];
            $changes[] = get_string('transfer_change_scope', 'mod_sideline', count($plan['scope']['cmids']));
        }
        if (in_array('rules', $parts, true)) {
            [$rules, $rulechanges, $rulewarnings] = self::preview_rules($sideline, $modinfo, $data['rules'], $conflict);
            $plan['rules'] = $rules;
            $changes = array_merge($changes, $rulechanges);
            $warnings = array_merge($warnings, $rulewarnings);
        }
        if (in_array('questions', $parts, true)) {
            foreach ($data['questions'] as $q) {
                $plan['questions'][] = (array) $q;
            }
            if ($plan['questions']) {
                $changes[] = get_string('transfer_change_questions', 'mod_sideline', count($plan['questions']));
            }
        }
        if (in_array('sources', $parts, true)) {
            [$plan['sources'], $sourcechanges, $sourcewarnings] = self::preview_sources(
                (int) $sideline->id,
                $data['sources'],
                $userid
            );
            $changes = array_merge($changes, $sourcechanges);
            $warnings = array_merge($warnings, $sourcewarnings);
        }
        if (in_array('groups', $parts, true)) {
            [$plan['groups'], $groupchanges, $groupwarnings] = self::preview_groups((int) $sideline->course, $data['groups']);
            $changes = array_merge($changes, $groupchanges);
            $warnings = array_merge($warnings, $groupwarnings);
        }
        if (in_array('personas', $parts, true)) {
            [$plan['personas'], $personachanges, $personawarnings] = self::preview_personas($data['personas'], $userid);
            $changes = array_merge($changes, $personachanges);
            $warnings = array_merge($warnings, $personawarnings);
        }

        // Sharing is never switched on for an implicit "all groups": it needs resolved groups from
        // this import or an existing restriction of the target.
        $mode = (string) ($plan['settings']['sharingmode'] ?? $sideline->sharingmode);
        $switchingon = $sideline->sharingmode === instance_config::GROUP_INDIVIDUAL && $mode !== instance_config::GROUP_INDIVIDUAL;
        if ($switchingon) {
            $restricted = $plan['groups'] !== null ? ($plan['groups']['all'] || $plan['groups']['groupids']
                || $plan['groups']['groupingids']) : (bool) array_filter(instance_config::get_groups((int) $sideline->id));
            if (!$restricted) {
                $plan['settings']['sharingmode'] = instance_config::GROUP_INDIVIDUAL;
                $warnings[] = get_string('transfer_sharing_off', 'mod_sideline');
            }
        }
        return ['changes' => $changes, 'warnings' => $warnings, 'plan' => $plan];
    }

    /**
     * Preview of the custom rules.
     *
     * @param \stdClass $sideline Target instance.
     * @param \course_modinfo $modinfo Target course.
     * @param array $items Rules of the file.
     * @param string $conflict replace, skip or rename.
     * @return array [plan entries, changes, warnings]
     */
    private static function preview_rules(\stdClass $sideline, \course_modinfo $modinfo, array $items, string $conflict): array {
        $plan = [];
        $changes = [];
        $warnings = [];
        $existing = array_column(rule_store::definitions((int) $sideline->id, false), null, 'key');
        $validcmids = array_map('intval', array_keys($modinfo->get_cms()));
        $seen = [];
        foreach ($items as $item) {
            $definition = (array) ($item['definition'] ?? []);
            foreach ($definition['conditions'] ?? [] as $i => $condition) {
                if (!is_array($condition)) {
                    continue;
                }
                if (!empty($condition['cmid'])) {
                    // Ids of the source course mean nothing here: only the name reference counts.
                    $mapped = isset($condition['activity'])
                        ? self::map_activity($modinfo, (array) $condition['activity'])[0] : null;
                    $definition['conditions'][$i]['cmid'] = $mapped ?: -1;
                }
                unset($definition['conditions'][$i]['activity']);
            }
            $key = (string) ($definition['key'] ?? '');
            if (isset($seen[$key])) {
                $warnings[] = get_string('transfer_rule_duplicate', 'mod_sideline', s($key));
                continue;
            }
            $seen[$key] = true;
            $action = 'add';
            if (isset($existing[$key])) {
                if ($conflict === 'skip') {
                    $warnings[] = get_string('transfer_rule_skipped', 'mod_sideline', s($key));
                    continue;
                }
                if ($conflict === 'rename') {
                    $definition['key'] = self::free_key($key, array_merge(array_keys($existing), array_keys($seen)));
                } else {
                    $action = 'replace';
                }
            }
            [$clean, $errors] = rule_schema::validate($definition, $validcmids);
            if ($clean === null) {
                $warnings[] = get_string('transfer_rule_invalid', 'mod_sideline', s($key . ': ' . implode(' ', $errors)));
                continue;
            }
            $plan[] = ['action' => $action, 'id' => $action === 'replace' ? (int) $existing[$key]['id'] : 0,
                'definition' => $clean, 'enabled' => !empty($item['enabled'])];
            $changes[] = get_string('transfer_change_rule_' . $action, 'mod_sideline', s($clean['key']));
        }
        return [$plan, $changes, $warnings];
    }

    /**
     * Preview of the course union: only courses and branches the importing teacher may manage.
     *
     * @param int $sidelineid Target instance.
     * @param array $sources courses and categories of the file.
     * @param int $userid Importing user.
     * @return array [plan entry, changes, warnings]
     */
    private static function preview_sources(int $sidelineid, array $sources, int $userid): array {
        global $DB;

        $changes = [];
        $warnings = [];
        $courseids = [];
        foreach ((array) ($sources['courses'] ?? []) as $ref) {
            $course = null;
            if (!empty($ref['idnumber'])) {
                $course = $DB->get_record('course', ['idnumber' => (string) $ref['idnumber']], 'id, shortname');
            }
            if (!$course && !empty($ref['shortname'])) {
                $course = $DB->get_record('course', ['shortname' => (string) $ref['shortname']], 'id, shortname');
            }
            $label = s((string) ($ref['shortname'] ?? $ref['idnumber'] ?? '?'));
            if (!$course) {
                $warnings[] = get_string('transfer_source_missing', 'mod_sideline', $label);
            } else if (!has_capability('moodle/course:manageactivities', \context_course::instance($course->id), $userid)) {
                $warnings[] = get_string('transfer_source_denied', 'mod_sideline', $label);
            } else {
                $courseids[] = (int) $course->id;
            }
        }
        $categoryids = [];
        foreach ((array) ($sources['categories'] ?? []) as $ref) {
            $categoryid = self::map_category((array) $ref);
            $label = s(implode(' / ', (array) ($ref['path'] ?? [])) ?: (string) ($ref['idnumber'] ?? '?'));
            if (!$categoryid) {
                $warnings[] = get_string('transfer_source_missing', 'mod_sideline', $label);
            } else if (!has_capability('moodle/course:manageactivities', \context_coursecat::instance($categoryid), $userid)) {
                $warnings[] = get_string('transfer_source_denied', 'mod_sideline', $label);
            } else {
                $categoryids[] = $categoryid;
            }
        }
        if ($courseids || $categoryids) {
            $changes[] = get_string('transfer_change_sources', 'mod_sideline', count($courseids) + count($categoryids));
        }
        $courseids = array_values(array_unique($courseids));
        $categoryids = array_values(array_unique($categoryids));
        // The import adds to the existing union: together they must stay within the limits of an instance,
        // otherwise saving would drop sources without saying so.
        $existing = array_map('intval', $DB->get_fieldset_select('sideline_source', 'courseid', 'sidelineid = ?', [$sidelineid]));
        if (count(array_unique(array_merge($existing, $courseids))) > instance_config::MAX_SOURCES) {
            self::limit('sources.courses', (string) instance_config::MAX_SOURCES);
        }
        $existing = $DB->get_fieldset_select('sideline_sourcecat', 'categoryid', 'sidelineid = ?', [$sidelineid]);
        $existing = array_map('intval', $existing);
        if (count(array_unique(array_merge($existing, $categoryids))) > instance_config::MAX_SOURCE_CATEGORIES) {
            self::limit('sources.categories', (string) instance_config::MAX_SOURCE_CATEGORIES);
        }
        return [['courseids' => $courseids, 'categoryids' => $categoryids], $changes, $warnings];
    }

    /**
     * Preview of the admitted groups: resolved by id number or name in the target course, never by guess.
     *
     * @param int $courseid Target course.
     * @param array $groups all, items (and unknown for files of older versions).
     * @return array [plan entry, changes, warnings]
     */
    private static function preview_groups(int $courseid, array $groups): array {
        global $DB;

        $changes = [];
        $warnings = [];
        $plan = ['all' => !empty($groups['all']) && empty($groups['unknown']), 'groupids' => [], 'groupingids' => [],
            'unresolved' => 0];
        // All groups and groupings of the course at once: a file may name a thousand of them. Names and ID numbers
        // are compared exactly, on every database alike.
        $known = [];
        foreach (['groups', 'groupings'] as $table) {
            $known[$table] = $DB->get_records($table, ['courseid' => $courseid], '', 'id, name, idnumber');
        }
        foreach ((array) ($groups['items'] ?? []) as $ref) {
            $table = ($ref['type'] ?? '') === 'grouping' ? 'groupings' : 'groups';
            $rows = [];
            if (!empty($ref['idnumber'])) {
                $rows = array_filter(
                    $known[$table],
                    static fn($row): bool => (string) $row->idnumber === (string) $ref['idnumber']
                );
            }
            if (!$rows && isset($ref['name'])) {
                $rows = array_filter($known[$table], static fn($row): bool => (string) $row->name === (string) $ref['name']);
            }
            $label = s((string) ($ref['name'] ?? '?'));
            if (count($rows) !== 1) {
                $problem = count($rows) ? 'transfer_group_ambiguous' : 'transfer_group_missing';
                $warnings[] = get_string($problem, 'mod_sideline', $label);
                $plan['unresolved']++;
                continue;
            }
            $plan[$table === 'groups' ? 'groupids' : 'groupingids'][] = (int) reset($rows)->id;
        }
        if ($plan['all'] || $plan['groupids'] || $plan['groupingids']) {
            $found = count($plan['groupids']) + count($plan['groupingids']);
            $changes[] = get_string('transfer_change_groups', 'mod_sideline', $found);
        }
        return [$plan, $changes, $warnings];
    }

    /**
     * Preview of the personas: definitions only for site administrators, always as drafts.
     *
     * @param array $personas Personas of the file.
     * @param int $userid Importing user.
     * @return array [plan entries, changes, warnings]
     */
    private static function preview_personas(array $personas, int $userid): array {
        global $DB;

        $plan = [];
        $changes = [];
        $warnings = [];
        $admin = has_capability('moodle/site:config', \context_system::instance(), $userid);
        foreach ($personas as $item) {
            $key = (string) ($item['key'] ?? '');
            $definition = is_array($item['definition'] ?? null) ? $item['definition'] : null;
            if (persona_registry::is_valid($key) || !$definition) {
                if (!persona_registry::is_valid($key)) {
                    $warnings[] = get_string('transfer_persona_unknown', 'mod_sideline', s($key));
                }
                continue;
            }
            if (!$admin) {
                $warnings[] = get_string('transfer_persona_denied', 'mod_sideline', s($key));
                continue;
            }
            $short = (string) ($definition['shortname'] ?? preg_replace('/^site_/', '', $key));
            if ($DB->record_exists('sideline_persona', ['shortname' => $short])) {
                $warnings[] = get_string('transfer_persona_exists', 'mod_sideline', s($key));
                continue;
            }
            $data = (object) ['shortname' => $short, 'name' => (string) ($definition['name'] ?? $short),
                'style' => (string) ($definition['style'] ?? ''), 'opener' => (string) ($definition['opener'] ?? '')];
            if ($errors = persona_manager::validate($data)) {
                $warnings[] = get_string('transfer_persona_invalid', 'mod_sideline', s($key . ': ' . implode(' ', $errors)));
                continue;
            }
            $plan[] = $data;
            $changes[] = get_string('transfer_change_persona', 'mod_sideline', s($key));
        }
        return [$plan, $changes, $warnings];
    }

    /**
     * Apply a previewed plan.
     *
     * @param \stdClass $sideline Target instance.
     * @param array $plan Plan from preview().
     */
    public static function apply(\stdClass $sideline, array $plan): void {
        global $DB;

        $userid = (int) ($plan['userid'] ?? 0);
        $transaction = $DB->start_delegated_transaction();
        // Persona definitions first: an offered persona of the settings can refer to them. They stay drafts,
        // so they are not offered to learners before an administrator approved them (unknown keys are dropped).
        foreach ($plan['personas'] ?? [] as $persona) {
            persona_manager::save($persona, $userid);
        }
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
        if (!empty($plan['sources'])) {
            // Added to the existing union, with the importing teacher's rights checked again on save.
            $courseids = array_merge(instance_config::get_sources((int) $sideline->id), $plan['sources']['courseids']);
            instance_config::save_sources($fresh, $courseids, $userid);
            $categoryids = array_merge(
                instance_config::get_source_categories((int) $sideline->id),
                $plan['sources']['categoryids']
            );
            instance_config::save_source_categories($fresh, $categoryids, $userid);
        }
        if (!empty($plan['groups'])) {
            $groups = $plan['groups'];
            if (!$groups['all'] && !$groups['groupids'] && !$groups['groupingids']) {
                // Nothing could be resolved: an empty list would admit every group, so sharing is switched off.
                $DB->set_field('sideline', 'sharingmode', instance_config::GROUP_INDIVIDUAL, ['id' => $sideline->id]);
            }
            instance_config::save_groups((int) $sideline->id, (int) $sideline->course, $groups['groupids'], $groups['groupingids']);
        }
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
        // Any change of mode or groups is compared with the circle learners decided for.
        instance_config::refresh_group_circle((int) $sideline->id);
        commentary_cache::invalidate_instance((int) $sideline->id);
        // Day counts belong to the sharing terms they were made under (mode, level, minimum size, event types).
        day_count::forget_if_terms_changed($sideline, $DB->get_record('sideline', ['id' => $sideline->id], '*', MUST_EXIST));
        $transaction->allow_commit();
        rebuild_course_cache((int) $sideline->course, true);
    }

    /**
     * The name reference of an activity: module type, name, section number.
     *
     * @param \cm_info $cm Activity.
     * @return array
     */
    private static function activity_ref(\cm_info $cm): array {
        return ['modname' => $cm->modname, 'name' => $cm->name, 'section' => (int) $cm->sectionnum];
    }

    /**
     * A readable label of an activity reference.
     *
     * @param array $activity Reference.
     * @return string
     */
    private static function activity_label(array $activity): string {
        return s((string) ($activity['modname'] ?? '?') . ': ' . (string) ($activity['name'] ?? '?'));
    }

    /**
     * Find an activity of the target course by module type and name, and section when several match.
     *
     * Never guesses: several activities of the same type and name in the same section are a
     * conflict that the teacher resolves by renaming, the reference then stays unmapped.
     *
     * @param \course_modinfo $modinfo Target course.
     * @param array $activity modname, name, optional section.
     * @return array [course module id or null, problem: "unmapped" or "ambiguous"]
     */
    private static function map_activity(\course_modinfo $modinfo, array $activity): array {
        // Index of the course's activities by type and name, built once per course structure.
        self::$activityindex ??= new \WeakMap();
        if (!isset(self::$activityindex[$modinfo])) {
            $index = [];
            foreach ($modinfo->get_cms() as $cm) {
                $index[$cm->modname . "\0" . $cm->name][] = $cm;
            }
            self::$activityindex[$modinfo] = $index;
        }
        $modname = $activity['modname'] ?? null;
        $name = $activity['name'] ?? null;
        $matches = is_string($modname) && is_string($name) ? (self::$activityindex[$modinfo][$modname . "\0" . $name] ?? []) : [];
        if (count($matches) > 1 && isset($activity['section'])) {
            $matches = array_values(array_filter($matches, static fn(\cm_info $cm): bool =>
                (int) $cm->sectionnum === (int) $activity['section']));
        }
        if (count($matches) === 1) {
            return [(int) $matches[0]->id, ''];
        }
        return [null, $matches ? 'ambiguous' : 'unmapped'];
    }

    /**
     * Find a category by id number, or by its path of names.
     *
     * @param array $ref idnumber, path (list of names from the top).
     * @return int|null
     */
    private static function map_category(array $ref): ?int {
        global $DB;

        if (!empty($ref['idnumber'])) {
            $id = $DB->get_field('course_categories', 'id', ['idnumber' => (string) $ref['idnumber']]);
            if ($id) {
                return (int) $id;
            }
        }
        $parent = 0;
        $id = null;
        foreach ((array) ($ref['path'] ?? []) as $name) {
            $ids = $DB->get_fieldset_select('course_categories', 'id', 'parent = ? AND name = ?', [$parent, (string) $name]);
            if (count($ids) !== 1) {
                return null;
            }
            $id = $parent = (int) reset($ids);
        }
        return $id;
    }

    /**
     * Names of a category and its parents, from the top.
     *
     * @param \core_course_category $category Category.
     * @return string[]
     */
    private static function category_names(\core_course_category $category): array {
        $names = [];
        foreach (array_filter(explode('/', $category->path)) as $id) {
            $names[] = (string) \core_course_category::get((int) $id, MUST_EXIST, true)->name;
        }
        return $names;
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
