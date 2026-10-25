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

use mod_sideline\local\dialog\question_type;
use mod_sideline\local\model\recommendation;

/**
 * Schema and validation of custom rules (P1-09).
 *
 * A custom rule is data, never code: a closed set of condition types, comparison operators and
 * placeholders. Everything else is rejected. Templates are plain text with the placeholders
 * {label}, {deadline}, {done} and {total}; no markup, no links.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rule_schema {
    /** @var int Version of the definition format. */
    public const FORMAT = 1;
    /** @var int Upper bound of custom rules per instance. */
    public const MAX_RULES = 20;
    /** @var int Upper bound of conditions per rule. */
    public const MAX_CONDITIONS = 10;
    /** @var int Longest template text. */
    public const MAX_TEMPLATE = 280;

    /** @var array Category names to recommendation categories. */
    public const CATEGORIES = [
        'safety' => recommendation::CAT_SAFETY,
        'next' => recommendation::CAT_NEXT,
        'success' => recommendation::CAT_SUCCESS,
        'orientation' => recommendation::CAT_ORIENTATION,
        'smalltalk' => recommendation::CAT_SMALLTALK,
    ];

    /** @var string[] Placeholders allowed in templates. */
    public const PLACEHOLDERS = ['label', 'deadline', 'done', 'total'];

    /**
     * Validate a definition and return it normalised.
     *
     * @param mixed $definition Decoded JSON.
     * @param int[] $validcmids Course modules that may be referenced (0 means any activity in scope).
     * @return array [normalised definition or null, list of error strings]
     */
    public static function validate(mixed $definition, array $validcmids): array {
        $errors = [];
        if (!is_array($definition)) {
            return [null, [get_string('err_rule_json', 'mod_sideline')]];
        }
        $out = [];
        $key = (string) ($definition['key'] ?? '');
        if (!preg_match('/^custom_[a-z0-9_]{1,40}$/', $key)) {
            $errors[] = get_string('err_rule_key', 'mod_sideline');
        }
        $out['key'] = $key;

        $category = (string) ($definition['category'] ?? 'next');
        if (!isset(self::CATEGORIES[$category])) {
            $errors[] = get_string('err_rule_field', 'mod_sideline', 'category');
        }
        $out['category'] = $category;
        $out['priority'] = min(100000, max(0, (int) ($definition['priority'] ?? 100)));
        $out['match'] = ($definition['match'] ?? 'all') === 'any' ? 'any' : 'all';
        $out['action'] = ($definition['action'] ?? 'none') === 'open' ? 'open' : 'none';
        $out['cooldown'] = min(365 * DAYSECS, max(0, (int) ($definition['cooldown'] ?? 0)));
        $out['maxperweek'] = min(100, max(0, (int) ($definition['maxperweek'] ?? 0)));

        $conditions = $definition['conditions'] ?? null;
        if (!is_array($conditions) || !$conditions || count($conditions) > self::MAX_CONDITIONS) {
            $errors[] = get_string('err_rule_conditions', 'mod_sideline', self::MAX_CONDITIONS);
            $conditions = [];
        }
        $out['conditions'] = [];
        foreach ($conditions as $condition) {
            [$normalised, $error] = self::condition($condition, $validcmids);
            if ($error) {
                $errors[] = $error;
            } else {
                $out['conditions'][] = $normalised;
            }
        }

        $templates = $definition['templates'] ?? null;
        $out['templates'] = [];
        if (!is_array($templates) || !$templates) {
            $errors[] = get_string('err_rule_templates', 'mod_sideline');
        } else {
            foreach ($templates as $lang => $text) {
                if (!is_string($lang) || !preg_match('/^[a-z]{2,3}(_[a-z0-9]+)*$/', $lang) || !is_string($text)) {
                    $errors[] = get_string('err_rule_templates', 'mod_sideline');
                    continue;
                }
                if ($error = self::template_error($text)) {
                    $errors[] = $error;
                    continue;
                }
                $out['templates'][$lang] = trim($text);
            }
        }
        return [$errors ? null : $out, $errors];
    }

    /**
     * Validate one condition.
     *
     * @param mixed $c Condition.
     * @param int[] $validcmids Referenceable course modules.
     * @return array [normalised condition, error string or null]
     */
    private static function condition(mixed $c, array $validcmids): array {
        if (!is_array($c)) {
            return [null, get_string('err_rule_condition', 'mod_sideline', '?')];
        }
        $type = (string) ($c['type'] ?? '');
        $cmid = (int) ($c['cmid'] ?? 0);
        if ($cmid && !in_array($cmid, $validcmids, true)) {
            return [null, get_string('err_rule_cmid', 'mod_sideline', $cmid)];
        }
        $tri = static fn($v) => in_array($v, ['yes', 'no'], true) ? $v : null;
        $op = static fn($v) => in_array($v, ['lt', 'gte'], true) ? $v : null;
        switch ($type) {
            case 'activity':
                $field = (string) ($c['field'] ?? '');
                $is = $tri($c['is'] ?? null);
                if (!in_array($field, ['completion', 'pass', 'available', 'submitted', 'attempts_left'], true) || !$is) {
                    break;
                }
                return [['type' => $type, 'cmid' => $cmid, 'field' => $field, 'is' => $is], null];
            case 'grade':
                $o = $op($c['op'] ?? null);
                if (!$o || !is_numeric($c['value'] ?? null)) {
                    break;
                }
                return [['type' => $type, 'cmid' => $cmid, 'op' => $o, 'value' => min(100, max(0, (float) $c['value']))], null];
            case 'deadline_within':
            case 'unused_for':
                $seconds = (int) ($c['seconds'] ?? 0);
                if ($seconds <= 0 || $seconds > 365 * DAYSECS) {
                    break;
                }
                return [['type' => $type, 'cmid' => $cmid, 'seconds' => $seconds], null];
            case 'progress':
                $o = $op($c['op'] ?? null);
                if (!$o || !is_numeric($c['value'] ?? null)) {
                    break;
                }
                return [['type' => $type, 'op' => $o, 'value' => min(100, max(0, (float) $c['value']))], null];
            case 'course_complete':
                $is = $tri($c['is'] ?? null);
                if (!$is) {
                    break;
                }
                return [['type' => $type, 'is' => $is], null];
            case 'effect':
                $effect = (string) ($c['effect'] ?? '');
                if (!in_array($effect, question_type::EFFECTS, true) || $effect === 'none') {
                    break;
                }
                return [['type' => $type, 'effect' => $effect], null];
        }
        return [null, get_string('err_rule_condition', 'mod_sideline', s($type))];
    }

    /**
     * Problems with a template text.
     *
     * @param string $text Template text.
     * @return string|null Error string or null.
     */
    public static function template_error(string $text): ?string {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > self::MAX_TEMPLATE) {
            return get_string('err_rule_templatelength', 'mod_sideline', self::MAX_TEMPLATE);
        }
        if (preg_match('/[<>]|https?:|:\/\/|www\./i', $text)) {
            return get_string('err_rule_templatemarkup', 'mod_sideline');
        }
        preg_match_all('/\{([^}]*)\}/', $text, $m);
        foreach ($m[1] as $name) {
            if (!in_array($name, self::PLACEHOLDERS, true)) {
                return get_string('err_rule_placeholder', 'mod_sideline', s($name));
            }
        }
        return null;
    }
}
