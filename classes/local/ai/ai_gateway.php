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

use mod_sideline\local\model\recommendation;

/**
 * Phrases recommendations with AI, or reports that it could not (L-14, P-25).
 *
 * The gateway never throws to its caller and never returns partial text: either every block is
 * validated and resolved, or the result is null and the caller serves the template text.
 *
 * Language (P-24, L-07): the text is generated directly in the learner's language whenever its
 * statements can be checked in that language (claim_checker). For other languages with a language
 * profile, it is generated and checked in English and then translated in a separate step whose
 * result must keep every token and number and must be in the target language. A response in the
 * wrong language is translated the same way. A language without a profile counts as unknown, and
 * the localised template is served.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_gateway {
    /** @var int Default cap of AI calls per site and hour (ADR-08). */
    public const DEFAULT_HOURLY_CAP = ai_usage::DEFAULT_HOURLY_CAP;

    /** @var string Status: AI text delivered, generated in the learner's language. */
    public const STATUS_OK = 'ok';
    /** @var string Status: AI text delivered after the translation step. */
    public const STATUS_TRANSLATED = 'translated';
    /** @var string Status: the learner's language cannot be checked, template served. */
    public const STATUS_UNSUPPORTED_LANGUAGE = 'unsupported_language';
    /** @var string Status: a cost cap was reached during the request. */
    public const STATUS_BUDGET = 'budget';
    /** @var string Status: the provider returned nothing. */
    public const STATUS_NO_RESPONSE = 'no_response';
    /** @var string Status: configured free text contains identifiers, nothing was sent (issue #4). */
    public const STATUS_UNSAFE_INPUT = 'unsafe_input';

    /** @var text_generator The seam to the AI subsystem. */
    private text_generator $generator;

    /** @var string Outcome of the last phrase() call: a status constant or "invalid:<reason>". */
    private string $laststatus = '';

    /** @var array What the last phrase() call exchanged, for the decision trace (issue #14): no real values. */
    private array $lastexchange = [];

    /**
     * Constructor.
     *
     * @param text_generator|null $generator Generator, defaults to the Moodle AI subsystem.
     */
    public function __construct(?text_generator $generator = null) {
        $this->generator = $generator ?? (behat_text_generator::enabled() ? new behat_text_generator() : new core_text_generator());
    }

    /**
     * Whether AI may be used for this activity, user and moment.
     *
     * All of these must hold: the site switch, the activity switch, the capability, the cost caps,
     * the learner's language, and the subsystem's own policy and availability checks.
     *
     * @param \stdClass $sideline Instance record.
     * @param int $userid User id.
     * @param \context_module $context Module context.
     * @param int $now Current time.
     * @param string|null $lang Language of the learner, defaults to the current language.
     * @param bool $budget Whether to check the cost caps too. The cache fingerprint uses the answer without
     *     them: reaching a cap must not make every cached AI text stale (phrase() checks the caps itself).
     * @return bool
     */
    public function is_allowed(
        \stdClass $sideline,
        int $userid,
        \context_module $context,
        int $now,
        ?string $lang = null,
        bool $budget = true,
    ): bool {
        if (!get_config('mod_sideline', 'aienabled') || empty($sideline->aienabled)) {
            return false;
        }
        // Per-role control of who gets AI phrasing at all (ADR-AI-01: no own placement, own capability).
        if (!has_capability('mod/sideline:useai', $context, $userid)) {
            return false;
        }
        // A language that cannot be checked never gets AI text (P-24: unknown language, localised fallback).
        if (!self::language_supported($lang ?? current_language())) {
            return false;
        }
        if ($budget && !ai_usage::within_budget($now)) {
            return false;
        }
        try {
            return $this->generator->is_available($userid, $context);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Whether AI text can be produced and checked for a language.
     *
     * @param string $lang Moodle language code.
     * @return bool
     */
    public static function language_supported(string $lang): bool {
        // Only languages in which invented facts can be recognised (issue #13): elsewhere the template is served.
        return claim_checker::supports($lang) && language_detector::supports($lang);
    }

    /**
     * Phrase the recommendations.
     *
     * @param recommendation[] $recommendations Ordered recommendations.
     * @param \stdClass $user The learner. Only the first name is used, and only locally.
     * @param \context $context Module context, or the system context for the persona preview.
     * @param string $persona Persona key.
     * @param string $lang Language code.
     * @param int $now Current time.
     * @param int $sidelineid Instance id, for the usage ledger.
     * @param string|null $style Persona style instead of the registry's: the persona preview (P-14).
     * @return string[]|null Final, resolved text per block key, or null when AI could not be used.
     */
    public function phrase(
        array $recommendations,
        \stdClass $user,
        \context $context,
        string $persona,
        string $lang,
        int $now,
        int $sidelineid = 0,
        ?string $style = null,
    ): ?array {
        $this->lastexchange = [];
        $purpose = $style === null ? ai_usage::GENERATE : ai_usage::PREVIEW;
        $target = language_detector::base_language($lang);
        if (!self::language_supported($target)) {
            return $this->status(self::STATUS_UNSUPPORTED_LANGUAGE);
        }
        // Generate in the learner's language when its statements can be checked there, otherwise in English.
        $genlang = claim_checker::supports($target) ? $lang : 'en';

        $format = get_string_manager()->get_string('strftimedatetimeshort', 'langconfig', null, $lang);
        // Free text configured by teachers is checked against the names of the people of the course (issue #4).
        $hasfreetext = (bool) array_filter($recommendations, static fn(recommendation $r): bool => !empty($r->facts['templates']));
        $coursecontext = $context->get_course_context(false);
        $names = $hasfreetext ? prompt_text_guard::names($coursecontext ? (int) $coursecontext->instanceid : 0, $user) : [];
        $request = new pseudonymizer($recommendations, (string) $user->firstname, $format, $now, $lang, $names);
        // The persona style is free text as well, written by administrators for the whole site: the same guard,
        // without the names of one course (a style is no place for them, and common words would match).
        $stylereason = prompt_text_guard::check($style ?? persona_registry::style_prompt($persona));
        $unsafe = $request->unsafe() ?? $stylereason;
        if ($unsafe !== null) {
            // Nothing leaves the site: the caller serves the localised template text (static fallback).
            return $this->status(self::STATUS_UNSAFE_INPUT . ':' . $unsafe);
        }
        $prompt = (new prompt_builder())->build($request, $persona, $genlang, $style);
        $this->lastexchange = ['prompt' => prompt_builder::VERSION, 'prompthash' => hash('sha256', $prompt), 'lang' => $genlang];

        $raw = $this->call($prompt, (int) $user->id, $context->id, $sidelineid, $purpose, $now);
        if ($raw === null) {
            return $this->laststatus === self::STATUS_BUDGET ? null : $this->status(self::STATUS_NO_RESPONSE);
        }
        $keys = array_map(static fn(recommendation $r): string => $r->key, $recommendations);
        $validator = new response_validator();
        $texts = $validator->validate($raw, $keys, $request);
        if ($texts === null) {
            return $this->status('invalid:' . $validator->reason);
        }

        // Which language did we get, block by block? Only languages with phrase lists count. A block too
        // short to tell takes the language of the whole answer.
        $written = self::languages($texts);
        if ($written === null) {
            return $this->status('invalid:language');
        }
        foreach ($written as $family) {
            if (!claim_checker::supports($family)) {
                return $this->status('invalid:language');
            }
        }
        // Claims are looked for in every block in every language, so a block in another language hides nothing.
        if ($problem = claim_checker::check_all($texts, $recommendations)) {
            return $this->status('invalid:claim:' . $problem);
        }

        $status = self::STATUS_OK;
        if (array_diff(array_unique($written), [$target])) {
            $texts = $this->translate($texts, $recommendations, $lang, (int) $user->id, $context->id, $sidelineid, $now, $purpose);
            if ($texts === null) {
                return null;
            }
            $status = self::STATUS_TRANSLATED;
        }
        $this->laststatus = $status;
        // The validated texts with tokens in place: what the provider said, without any real value.
        $this->lastexchange['response'] = $texts;
        // Resolve only after validation, locally.
        return array_map(static fn(string $text): string => $request->resolve($text), $texts);
    }

    /**
     * The separate, checked translation step (P-24).
     *
     * @param string[] $texts Checked texts per block key, tokens in place.
     * @param recommendation[] $recommendations The recommendations, for the claim check in the target language.
     * @param string $lang Target language code.
     * @param int $userid User id.
     * @param int $contextid Context id.
     * @param int $sidelineid Instance id.
     * @param int $now Current time.
     * @param string $purpose Ledger purpose of the generation: a preview stays a preview.
     * @return string[]|null Translated texts, tokens in place, or null.
     */
    private function translate(
        array $texts,
        array $recommendations,
        string $lang,
        int $userid,
        int $contextid,
        int $sidelineid,
        int $now,
        string $purpose,
    ): ?array {
        $target = language_detector::base_language($lang);
        $prompt = (new prompt_builder())->build_translation($texts, $lang);
        $purpose = $purpose === ai_usage::PREVIEW ? ai_usage::PREVIEW : ai_usage::TRANSLATE;
        $raw = $this->call($prompt, $userid, $contextid, $sidelineid, $purpose, $now);
        if ($raw === null) {
            return $this->laststatus === self::STATUS_BUDGET ? null : $this->status('invalid:translation_no_response');
        }
        $validator = new response_validator();
        $translated = $validator->validate_translation($raw, $texts);
        if ($translated === null) {
            return $this->status('invalid:' . $validator->reason);
        }
        // Every block must now be in the target language: a block left untranslated is rejected.
        $languages = self::languages($translated);
        if ($languages === null || array_diff(array_unique($languages), [$target])) {
            return $this->status('invalid:translation_language');
        }
        // The translation is checked for invented facts again (issue #13). A target without phrase lists never
        // gets this far (language_supported()), and is refused here as well: unchecked text is never delivered.
        if (!claim_checker::supports($target)) {
            return $this->status('invalid:translation_unverifiable');
        }
        if ($problem = claim_checker::check_all($translated, $recommendations)) {
            return $this->status('invalid:translation_claim:' . $problem);
        }
        return $translated;
    }

    /**
     * The language family of each block; a block that cannot be told on its own takes the whole answer's.
     *
     * @param string[] $texts Text per block key.
     * @return string[]|null Family per block key, or null when the whole answer cannot be told either.
     */
    private static function languages(array $texts): ?array {
        $overall = language_detector::detect(implode(' ', $texts));
        $result = [];
        foreach ($texts as $key => $text) {
            $family = language_detector::detect($text);
            $result[$key] = $family === language_detector::UNKNOWN ? $overall : $family;
            if ($result[$key] === language_detector::UNKNOWN) {
                return null;
            }
        }
        return $result;
    }

    /**
     * One provider call, within the caps and recorded in the ledger.
     *
     * @param string $prompt Prompt.
     * @param int $userid User id.
     * @param int $contextid Context id.
     * @param int $sidelineid Instance id.
     * @param string $purpose Ledger purpose.
     * @param int $now Current time.
     * @return string|null Raw response.
     */
    private function call(string $prompt, int $userid, int $contextid, int $sidelineid, string $purpose, int $now): ?string {
        // Reserve the call in the ledger before it starts, so concurrent requests see it at once.
        $reservation = ai_usage::reserve($sidelineid, $purpose, $prompt, $now);
        if ($reservation === null) {
            $this->laststatus = self::STATUS_BUDGET;
            return null;
        }
        $raw = null;
        $tokens = null;
        try {
            $raw = $this->generator->generate($prompt, $userid, $contextid);
            $tokens = $this->generator->last_tokens();
        } catch (\Throwable $e) {
            // A provider outage is a failed call: it is completed and counted, never left open or retried here.
            $raw = null;
        } finally {
            ai_usage::complete($reservation, $raw !== null, $tokens, $prompt, $raw);
        }
        return $raw;
    }

    /**
     * Record the outcome and return null.
     *
     * @param string $status Status.
     * @return null
     */
    private function status(string $status): ?array {
        $this->laststatus = $status;
        return null;
    }

    /**
     * What the last phrase() call sent and received, without real values (issue #14).
     *
     * @return array prompt version, prompt hash, generation language and the validated texts with tokens.
     */
    public function last_exchange(): array {
        return $this->lastexchange;
    }

    /**
     * Outcome of the last phrase() call, for traces and the debug export.
     *
     * @return string
     */
    public function last_status(): string {
        return $this->laststatus;
    }
}
