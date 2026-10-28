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

/**
 * Versions and variable schemas of the message templates (P-07).
 *
 * A built-in template is the language string "tpl_<key>" in every language pack. Its schema says
 * which variables it may use and of which type; the type decides how message_composer formats the
 * value (plain text, integer, date in the user's time zone, list of names). Every template has its
 * own version number: whoever changes the wording of a template in the language packs raises it
 * here, and with it VERSION, which is part of every cache fingerprint. Custom rule templates are
 * versioned per rule by rule_store.
 *
 * The output of every template is plain text: values are inserted as text, markup is removed, and
 * the front end renders the result as text, never as HTML.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_registry {
    /** @var int Version of the built-in template set: raise it with any template version below. */
    public const VERSION = 1;

    /** @var string Variable type: plain text. */
    public const TEXT = 'text';
    /** @var string Variable type: integer. */
    public const INT = 'int';
    /** @var string Variable type: date and time, formatted for the user. */
    public const DATE = 'date';
    /** @var string Variable type: list of names, joined with commas. */
    public const NAMES = 'names';

    /** @var array Template key to version and variable schema. */
    public const TEMPLATES = [
        'course_complete' => ['version' => 1, 'vars' => []],
        'deadline_passed' => ['version' => 1, 'vars' => ['label' => self::TEXT, 'deadline' => self::DATE]],
        'deadline_soon' => ['version' => 1, 'vars' => ['label' => self::TEXT, 'deadline' => self::DATE]],
        'generic' => ['version' => 1, 'vars' => []],
        'group_named' => ['version' => 1, 'vars' => ['names' => self::NAMES, 'label' => self::TEXT]],
        'group_named_with_group' => ['version' => 1, 'vars' => ['names' => self::NAMES, 'group' => self::TEXT,
            'label' => self::TEXT]],
        'group_only' => ['version' => 1, 'vars' => ['count' => self::INT, 'group' => self::TEXT, 'label' => self::TEXT]],
        'group_others' => ['version' => 1, 'vars' => ['count' => self::INT, 'label' => self::TEXT]],
        'group_together' => ['version' => 1, 'vars' => ['count' => self::INT, 'label' => self::TEXT]],
        'locked_completion' => ['version' => 1, 'vars' => ['label' => self::TEXT]],
        'locked_grade' => ['version' => 1, 'vars' => ['label' => self::TEXT]],
        'locked_group' => ['version' => 1, 'vars' => ['label' => self::TEXT]],
        'locked_opens' => ['version' => 1, 'vars' => ['label' => self::TEXT, 'opensat' => self::DATE]],
        'locked_requirements' => ['version' => 1, 'vars' => ['label' => self::TEXT]],
        'not_passed' => ['version' => 1, 'vars' => ['label' => self::TEXT]],
        'not_passed_final' => ['version' => 1, 'vars' => ['label' => self::TEXT]],
        'not_passed_retry' => ['version' => 1, 'vars' => ['label' => self::TEXT]],
        'opens_later' => ['version' => 1, 'vars' => ['label' => self::TEXT, 'opensat' => self::DATE]],
        'progress' => ['version' => 1, 'vars' => ['done' => self::INT, 'total' => self::INT]],
        'small_step' => ['version' => 1, 'vars' => ['nextlabel' => self::TEXT]],
        'smalltalk' => ['version' => 1, 'vars' => []],
        'submitted_waiting' => ['version' => 1, 'vars' => ['label' => self::TEXT]],
        'success' => ['version' => 1, 'vars' => ['label' => self::TEXT]],
        'success_next' => ['version' => 1, 'vars' => ['label' => self::TEXT, 'nextlabel' => self::TEXT]],
        'welcome_back' => ['version' => 1, 'vars' => ['nextlabel' => self::TEXT]],
        'welcome_back_gentle' => ['version' => 1, 'vars' => ['nextlabel' => self::TEXT]],
    ];

    /** @var array Variables of custom rule templates and their types. */
    public const CUSTOM_VARS = ['label' => self::TEXT, 'deadline' => self::DATE, 'done' => self::INT, 'total' => self::INT];

    /**
     * Whether a built-in template exists.
     *
     * @param string $key Template key.
     * @return bool
     */
    public static function exists(string $key): bool {
        return isset(self::TEMPLATES[$key]);
    }

    /**
     * The version tag of the template behind a block, for the commentary meta data and traces.
     *
     * @param string $key Rule key.
     * @param array $facts Facts of the recommendation; custom rules carry their template version there.
     * @return string "builtin:<key>@<version>" or "custom:<key>@<version>".
     */
    public static function tag(string $key, array $facts = []): string {
        if (!empty($facts['templates'])) {
            return 'custom:' . $key . '@' . max(1, (int) ($facts['templateversion'] ?? 1));
        }
        return 'builtin:' . $key . '@' . (self::TEMPLATES[$key]['version'] ?? 0);
    }

    /**
     * Variables used by a language string, from its "{$a->name}" placeholders.
     *
     * @param string $text Raw language string.
     * @return string[]
     */
    public static function used_vars(string $text): array {
        preg_match_all('/\{\$a->([a-z]+)\}/', $text, $m);
        return array_values(array_unique($m[1]));
    }
}
