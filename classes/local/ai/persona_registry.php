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

namespace mod_sideline\local\ai;

/**
 * The persona registry (L-05, P-10 to P-13, P1-07).
 *
 * Personas are three built-in ones plus site personas defined by administrators. A site persona is
 * usable only while its status is "approved"; editing it creates a new version that is a draft
 * until approved again, and revoking it takes effect on the next request because the persona key,
 * status and version are part of every cache fingerprint.
 *
 * A persona only shapes wording. Safety and fact rules live in prompt_builder and cannot be
 * overridden from here; the response validator applies to every persona alike.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class persona_registry {
    /** @var string Persona used when nothing valid is configured. */
    public const DEFAULT_KEY = 'mentor';

    /** @var int Version of the built-in persona definitions. Part of the cache fingerprint. */
    public const VERSION = 1;

    /** @var string Prefix of site persona keys. */
    public const SITE_PREFIX = 'site_';

    /** @var array|null Approved site personas keyed by persona key, per request. */
    private static ?array $site = null;

    /**
     * Usable persona keys: built-in and approved site personas.
     *
     * @return string[]
     */
    public static function keys(): array {
        return array_merge(array_keys(self::builtins()), array_keys(self::site_personas()));
    }

    /**
     * Whether a persona key exists and may be used now.
     *
     * @param string $key Persona key.
     * @return bool
     */
    public static function is_valid(string $key): bool {
        return array_key_exists($key, self::builtins()) || array_key_exists($key, self::site_personas());
    }

    /**
     * Persona options for a select element: key to display name.
     *
     * @return string[]
     */
    public static function options(): array {
        $options = [];
        foreach (array_keys(self::builtins()) as $key) {
            $options[$key] = get_string('persona_' . $key, 'mod_sideline');
        }
        foreach (self::site_personas() as $key => $row) {
            $options[$key] = format_string($row->name, true, ['context' => \context_system::instance()]);
        }
        return $options;
    }

    /**
     * The style instruction for the AI persona layer, in English, independent of the user language.
     *
     * @param string $key Persona key, unknown keys fall back to the default persona.
     * @return string
     */
    public static function style_prompt(string $key): string {
        $site = self::site_personas();
        if (isset($site[$key])) {
            return (string) $site[$key]->style;
        }
        $builtins = self::builtins();
        return $builtins[$key]['style'] ?? $builtins[self::DEFAULT_KEY]['style'];
    }

    /**
     * The style instruction of a built-in persona, or null for other keys.
     *
     * @param string $key Persona key.
     * @return string|null
     */
    public static function builtin_style(string $key): ?string {
        return self::builtins()[$key]['style'] ?? null;
    }

    /**
     * Keys of the built-in personas.
     *
     * @return string[]
     */
    public static function builtin_keys(): array {
        return array_keys(self::builtins());
    }

    /**
     * The persona opener used before the main template message.
     *
     * @param string $key Persona key.
     * @param string $lang Language code.
     * @return string Plain text, may be empty.
     */
    public static function opener(string $key, string $lang): string {
        $site = self::site_personas();
        if (isset($site[$key])) {
            return trim(clean_param((string) $site[$key]->opener, PARAM_NOTAGS));
        }
        $key = array_key_exists($key, self::builtins()) ? $key : self::DEFAULT_KEY;
        return get_string_manager()->get_string('opener_' . $key, 'mod_sideline', null, $lang);
    }

    /**
     * Version tag of a persona, for the cache fingerprint.
     *
     * @param string $key Persona key.
     * @return string
     */
    public static function version(string $key): string {
        $site = self::site_personas();
        if (isset($site[$key])) {
            return 'site:' . (int) $site[$key]->id . ':' . (int) $site[$key]->version;
        }
        return 'builtin:' . self::VERSION;
    }

    /**
     * Forget the per-request cache, after administrators changed site personas.
     */
    public static function reset(): void {
        self::$site = null;
    }

    /**
     * Approved site personas keyed by persona key.
     *
     * @return \stdClass[]
     */
    private static function site_personas(): array {
        global $DB;

        if (self::$site === null) {
            self::$site = [];
            try {
                foreach ($DB->get_records('sideline_persona', ['status' => 'approved'], 'shortname') as $row) {
                    self::$site[self::SITE_PREFIX . $row->shortname] = $row;
                }
            } catch (\dml_exception $e) {
                // During installation the table may not exist yet.
                self::$site = [];
            }
        }
        return self::$site;
    }

    /**
     * Built-in definitions.
     *
     * @return array
     */
    private static function builtins(): array {
        return [
            'reporter' => [
                'style' => 'You are a factual sports reporter commenting on a learner\'s progress. '
                    . 'Describe events concisely, in a lively sports-commentary tone. You may occasionally ask '
                    . 'one reflective question. Never belittle the learner or attribute abilities or traits to them.',
            ],
            'cornercoach' => [
                'style' => 'You are an energetic corner coach in a boxing gym. Be direct and motivating, use '
                    . 'sports metaphors, give clear short instructions. Never insult, threaten, shame or pressure '
                    . 'the learner.',
            ],
            'mentor' => [
                'style' => 'You are a warm, appreciative mentor. Focus on strengths and resources, keep a calm '
                    . 'and encouraging tone, and where it fits ask one open, solution-oriented question. Never '
                    . 'diagnose, never give therapy, never attribute psychological states to the learner.',
            ],
        ];
    }
}
