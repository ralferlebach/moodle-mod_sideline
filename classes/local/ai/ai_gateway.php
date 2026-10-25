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
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_gateway {
    /** @var int Default cap of AI generations per site and hour (ADR-08, to be decided by the site). */
    public const DEFAULT_HOURLY_CAP = 200;

    /** @var text_generator The seam to the AI subsystem. */
    private text_generator $generator;

    /**
     * Constructor.
     *
     * @param text_generator|null $generator Generator, defaults to the Moodle AI subsystem.
     */
    public function __construct(?text_generator $generator = null) {
        $this->generator = $generator ?? new core_text_generator();
    }

    /**
     * Whether AI may be used for this activity, user and moment.
     *
     * All of these must hold: the site switch, the activity switch, the cost cap, and the
     * subsystem's own policy and availability checks.
     *
     * @param \stdClass $sideline Instance record.
     * @param int $userid User id.
     * @param \context_module $context Module context.
     * @param int $now Current time.
     * @return bool
     */
    public function is_allowed(\stdClass $sideline, int $userid, \context_module $context, int $now): bool {
        if (!get_config('mod_sideline', 'aienabled') || empty($sideline->aienabled)) {
            return false;
        }
        // Per-role control of who gets AI phrasing at all (ADR-AI-01: no own placement, own capability).
        if (!has_capability('mod/sideline:useai', $context, $userid)) {
            return false;
        }
        if (!$this->within_budget($now)) {
            return false;
        }
        try {
            return $this->generator->is_available($userid, $context);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Phrase the recommendations.
     *
     * @param recommendation[] $recommendations Ordered recommendations.
     * @param \stdClass $user The learner. Only the first name is used, and only locally.
     * @param \context_module $context Module context.
     * @param string $persona Persona key.
     * @param string $lang Language code.
     * @param int $now Current time.
     * @return string[]|null Final, resolved text per block key, or null when AI could not be used.
     */
    public function phrase(
        array $recommendations,
        \stdClass $user,
        \context_module $context,
        string $persona,
        string $lang,
        int $now,
    ): ?array {
        $format = get_string_manager()->get_string('strftimedatetimeshort', 'langconfig', null, $lang);
        $request = new pseudonymizer($recommendations, (string) $user->firstname, $format, $now, $lang);
        $prompt = (new prompt_builder())->build($request, $persona, $lang);

        $raw = $this->generator->generate($prompt, (int) $user->id, $context->id);
        if ($raw === null) {
            return null;
        }
        $keys = array_map(static fn(recommendation $r): string => $r->key, $recommendations);
        $validated = (new response_validator())->validate($raw, $keys, $request);
        if ($validated === null) {
            return null;
        }
        // Resolve only after validation, locally.
        return array_map(static fn(string $text): string => $request->resolve($text), $validated);
    }

    /**
     * Simple site-wide cost control: generations in the last hour against the configured cap.
     *
     * @param int $now Current time.
     * @return bool
     */
    private function within_budget(int $now): bool {
        global $DB;

        $cap = (int) get_config('mod_sideline', 'aihourlycap');
        if ($cap <= 0) {
            $cap = self::DEFAULT_HOURLY_CAP;
        }
        $used = $DB->count_records_select('sideline_cache', "source = 'ai' AND timemodified > ?", [$now - HOURSECS]);
        return $used < $cap;
    }
}
