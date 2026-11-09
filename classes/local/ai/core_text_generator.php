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

use core_ai\aiactions\generate_text;
use core_ai\manager;

/**
 * Text generation through Moodle's AI subsystem (core_ai\manager and the generate_text action).
 *
 * Decided in ADR-AI-01 (P-21): no dedicated aiplacement_sideline. This class performs the checks of
 * the subsystem explicitly and never bypasses them; the own capability mod/sideline:useai controls
 * per role who gets AI phrasing.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class core_text_generator implements text_generator {
    /** @var int|null Tokens of the last call. */
    private ?int $lasttokens = null;

    #[\Override]
    public function is_available(int $userid, \context $context): bool {
        $manager = \core\di::get(manager::class);

        // Note: manager::user_policy_accepted() RECORDS an acceptance. The read is get_user_policy_status().
        if (!manager::get_user_policy_status($userid)) {
            return false;
        }
        if (!$manager->is_action_available(generate_text::class)) {
            return false;
        }
        // Moodle 5.1+ can switch AI off per course and per course module. Older versions have no such switch.
        if (method_exists($manager, 'is_action_enabled_in_context')) {
            return $manager->is_action_enabled_in_context($context, generate_text::class);
        }
        return true;
    }

    /**
     * Generate a text through the AI subsystem.
     *
     * The action is registered in the learner's user context, not in the activity's: Moodle's AI usage
     * report shows teachers every AI call in their course with the person's name, which would tell them
     * who uses the coach and so who consented (P-40). Whether AI may be used in the activity is decided
     * with the activity's context in is_available(). The entry the call causes is recorded for the
     * instance (ai_records), so that a withdrawal empties exactly the entries of this instance.
     *
     * @param string $prompt Prompt.
     * @param int $userid Learner.
     * @param int $contextid Context of the activity (not used for the registration, see above).
     * @return string|null
     */
    #[\Override]
    public function generate(string $prompt, int $userid, int $contextid): ?string {
        $this->lasttokens = null;
        $usercontext = \context_user::instance($userid, IGNORE_MISSING);
        $registered = $usercontext ? (int) $usercontext->id : $contextid;
        $sidelineid = self::instance_of($contextid);
        // One call of the plugin per person at a time, so that the entry it causes is known for certain.
        $lock = ai_records::lock($userid);
        if (!$lock) {
            return null;
        }
        try {
            $after = ai_records::last_id();
            $action = new generate_text(
                contextid: $registered,
                userid: $userid,
                prompttext: $prompt
            );
            try {
                $response = \core\di::get(manager::class)->process_action($action);
            } finally {
                // Also a failed call leaves its prompt with the subsystem.
                if ($sidelineid) {
                    ai_records::attribute($sidelineid, $userid, $registered, $after, $prompt, time());
                }
            }
        } catch (\Throwable $e) {
            // Provider failure, timeout or misconfiguration: the caller falls back to templates.
            debugging('mod_sideline: AI generation failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        } finally {
            $lock->release();
        }
        if (!$response->get_success()) {
            return null;
        }
        $data = $response->get_response_data();
        if (isset($data['prompttokens']) || isset($data['completiontokens'])) {
            $this->lasttokens = (int) ($data['prompttokens'] ?? 0) + (int) ($data['completiontokens'] ?? 0);
        }
        $content = $data['generatedcontent'] ?? null;
        return is_string($content) && trim($content) !== '' ? $content : null;
    }

    /**
     * The Sideline instance of an activity context, or 0 (a persona preview has none).
     *
     * @param int $contextid Context id.
     * @return int
     */
    private static function instance_of(int $contextid): int {
        $context = \context::instance_by_id($contextid, IGNORE_MISSING);
        if (!$context instanceof \context_module) {
            return 0;
        }
        $cm = get_coursemodule_from_id('sideline', $context->instanceid, 0, false, IGNORE_MISSING);
        return $cm ? (int) $cm->instance : 0;
    }

    #[\Override]
    public function last_tokens(): ?int {
        return $this->lasttokens;
    }
}
