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

/**
 * Test double for the AI subsystem seam.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_sideline\tests;

use mod_sideline\local\ai\text_generator;

/**
 * A scripted text generator. Records every prompt it receives, so tests can assert what would have
 * left the site, and counts calls, so tests can assert that no call was made.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fake_text_generator implements text_generator {
    /** @var string[] Prompts received. */
    public array $prompts = [];
    /** @var int Number of generate() calls. */
    public int $calls = 0;
    /** @var bool Value returned by is_available(). */
    public bool $available = true;
    /** @var callable|null Builds the response from the prompt, null response when it returns null. */
    public $responder = null;

    #[\Override]
    public function is_available(int $userid, \context $context): bool {
        return $this->available;
    }

    #[\Override]
    public function generate(string $prompt, int $userid, int $contextid): ?string {
        $this->calls++;
        $this->prompts[] = $prompt;
        return $this->responder ? ($this->responder)($prompt) : null;
    }

    /**
     * A responder that answers every block with a text built from the required tokens of the prompt.
     *
     * @param string $suffix Text appended to every block, for example an invented number.
     * @return callable
     */
    public static function echoing_tokens(string $suffix = ''): callable {
        return static function (string $prompt) use ($suffix): string {
            preg_match('/FACTS:\n(.*?)\n\nOUTPUT FORMAT/s', $prompt, $m);
            $facts = json_decode($m[1], true);
            $blocks = [];
            foreach ($facts['blocks'] as $block) {
                $text = 'Coach says ' . implode(' and ', $block['required_tokens']) . $suffix;
                $blocks[] = ['key' => $block['key'], 'text' => trim($text)];
            }
            return json_encode(['blocks' => $blocks]);
        };
    }
}
