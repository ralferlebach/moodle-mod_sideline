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

namespace mod_sideline\local\persona;

use mod_sideline\local\ai\ai_gateway;
use mod_sideline\local\ai\ai_usage;
use mod_sideline\local\ai\persona_registry;
use mod_sideline\local\ai\prompt_builder;
use mod_sideline\local\ai\pseudonymizer;
use mod_sideline\local\ai\text_generator;
use mod_sideline\local\model\recommendation;
use mod_sideline\local\service\message_composer;

/**
 * Preview of a persona before and after approval (P-14).
 *
 * The preview uses invented sample situations only: a deadline, a failed attempt with another try,
 * and a success with a next step, for an invented learner "Alex". No learner data is read. It shows
 * what the persona changes: the template text with the persona's opener, the complete prompt the
 * provider would receive (with tokens instead of names and dates), and, when AI is available to
 * the administrator, a generated sample that went through the same checks as every learner text.
 * A preview with AI counts against the site's cost caps like any other call.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class persona_preview {
    /** @var string The invented learner. */
    public const SAMPLE_NAME = 'Alex';

    /** @var string Persona key used for a draft in the prompt; never stored. */
    private const PREVIEW_KEY = 'preview';

    /** @var string Style instruction. */
    private string $style;

    /** @var string Opener. */
    private string $opener;

    /**
     * Constructor.
     *
     * @param string $style Style instruction of the persona.
     * @param string $opener Opener of the persona, plain text.
     */
    public function __construct(string $style, string $opener) {
        $this->style = $style;
        $this->opener = trim(clean_param($opener, PARAM_NOTAGS));
    }

    /**
     * Preview of a site persona in any status, by id.
     *
     * @param int $id Persona id.
     * @return self
     */
    public static function for_site_persona(int $id): self {
        global $DB;

        $row = $DB->get_record('sideline_persona', ['id' => $id], '*', MUST_EXIST);
        return new self((string) $row->style, (string) $row->opener);
    }

    /**
     * Preview of a built-in persona.
     *
     * @param string $key Built-in persona key.
     * @param string $lang Language for the opener.
     * @return self
     */
    public static function for_builtin(string $key, string $lang): self {
        $style = persona_registry::builtin_style($key);
        if ($style === null) {
            throw new \invalid_parameter_exception('Unknown persona');
        }
        return new self($style, persona_registry::opener($key, $lang));
    }

    /**
     * The invented sample situations.
     *
     * @param int $now Current time.
     * @return recommendation[]
     */
    public static function samples(int $now): array {
        $quiz = get_string('preview_sample_quiz', 'mod_sideline');
        $essay = get_string('preview_sample_essay', 'mod_sideline');
        $next = get_string('preview_sample_next', 'mod_sideline');
        return [
            new recommendation(
                'deadline_soon',
                recommendation::CAT_SAFETY,
                1,
                recommendation::ACTION_NONE,
                null,
                ['label' => $essay, 'deadline' => $now + 2 * DAYSECS]
            ),
            new recommendation(
                'not_passed_retry',
                recommendation::CAT_NEXT,
                2,
                recommendation::ACTION_NONE,
                null,
                ['label' => $quiz]
            ),
            new recommendation(
                'success_next',
                recommendation::CAT_SUCCESS,
                3,
                recommendation::ACTION_NONE,
                null,
                ['label' => $quiz, 'nextlabel' => $next, 'outcome' => 'passed']
            ),
        ];
    }

    /**
     * Template texts with the persona's opener on the first block.
     *
     * @param string $lang Language code.
     * @param int $now Current time.
     * @return string[] Text per block key.
     */
    public function template_texts(string $lang, int $now): array {
        $composer = new message_composer();
        $commentary = $composer->compose(self::samples($now), [], persona_registry::DEFAULT_KEY, $lang, $this->opener);
        return array_column($commentary['blocks'], 'text', 'key');
    }

    /**
     * The complete prompt for the samples, as the provider would receive it.
     *
     * @param string $lang Language code.
     * @param int $now Current time.
     * @return string
     */
    public function prompt(string $lang, int $now): string {
        $format = get_string_manager()->get_string('strftimedatetimeshort', 'langconfig', null, $lang);
        $request = new pseudonymizer(self::samples($now), self::SAMPLE_NAME, $format, $now, $lang);
        return (new prompt_builder())->build($request, self::PREVIEW_KEY, $lang, $this->style);
    }

    /**
     * Whether an AI sample can be generated for this administrator now.
     *
     * @param text_generator $generator Generator.
     * @param int $userid Administrator.
     * @param int $now Current time.
     * @return bool
     */
    public static function ai_available(text_generator $generator, int $userid, int $now): bool {
        if (!get_config('mod_sideline', 'aienabled') || !ai_usage::within_budget($now)) {
            return false;
        }
        try {
            return $generator->is_available($userid, \context_system::instance());
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Generate an AI sample through the same pipeline and checks as learner texts.
     *
     * @param ai_gateway $gateway Gateway.
     * @param int $userid Administrator; the provider sees only the sample tokens.
     * @param string $lang Language code.
     * @param int $now Current time.
     * @return array ['texts' => string[]|null, 'status' => string]
     */
    public function ai_sample(ai_gateway $gateway, int $userid, string $lang, int $now): array {
        $user = (object) ['id' => $userid, 'firstname' => self::SAMPLE_NAME];
        $texts = $gateway->phrase(
            self::samples($now),
            $user,
            \context_system::instance(),
            self::PREVIEW_KEY,
            $lang,
            $now,
            0,
            $this->style
        );
        return ['texts' => $texts, 'status' => $gateway->last_status()];
    }
}
