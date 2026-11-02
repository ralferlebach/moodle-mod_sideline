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
 * A deterministic stand-in for the AI provider, for Behat acceptance tests only.
 *
 * It is used only on a Behat test site (BEHAT_SITE_RUNNING) on which the step "the Sideline AI is
 * simulated" switched it on; everywhere else ai_gateway uses the Moodle AI subsystem. It answers
 * every block with a fixed English sentence that contains the required tokens, so a scenario can
 * tell an AI text from a template text, and it records how many prompts it received, so that a
 * scenario can show that nothing was sent.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_text_generator implements text_generator {
    /** @var string Prefix of every simulated text. */
    public const PREFIX = 'Simulated coach:';

    /**
     * Whether the simulation is switched on for this site.
     *
     * @return bool
     */
    public static function enabled(): bool {
        return defined('BEHAT_SITE_RUNNING') && BEHAT_SITE_RUNNING && get_config('mod_sideline', 'behatfakeai');
    }

    #[\Override]
    public function is_available(int $userid, \context $context): bool {
        return self::enabled();
    }

    #[\Override]
    public function generate(string $prompt, int $userid, int $contextid): ?string {
        if (!self::enabled()) {
            return null;
        }
        set_config('behatfakeaicalls', (int) get_config('mod_sideline', 'behatfakeaicalls') + 1, 'mod_sideline');
        if (!preg_match('/FACTS:\n(.*?)\n\nOUTPUT FORMAT/s', $prompt, $m)) {
            return null;
        }
        $blocks = [];
        foreach (json_decode($m[1], true)['blocks'] ?? [] as $block) {
            $blocks[] = ['key' => $block['key'], 'text' => self::PREFIX . ' this is about '
                . implode(' and ', $block['required_tokens'] ?? []) . ', keep going with the course.'];
        }
        return json_encode(['blocks' => $blocks]);
    }

    #[\Override]
    public function last_tokens(): ?int {
        return 10;
    }
}
