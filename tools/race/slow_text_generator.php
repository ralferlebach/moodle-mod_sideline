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

namespace mod_sideline\tools;

/**
 * A provider double that takes its time, so that other processes act while a generation is in flight.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class slow_text_generator implements \mod_sideline\local\ai\text_generator {
    /** @var int Number of calls. */
    public int $calls = 0;

    /**
     * Constructor.
     *
     * @param int $millis Time every call takes.
     * @param string $signal File created when the first call starts, empty for none.
     */
    public function __construct(
        /** @var int Time every call takes. */
        private int $millis,
        /** @var string File created when the first call starts. */
        private string $signal = ''
    ) {
    }

    #[\Override]
    public function is_available(int $userid, \context $context): bool {
        return true;
    }

    #[\Override]
    public function generate(string $prompt, int $userid, int $contextid): ?string {
        $this->calls++;
        if ($this->signal !== '' && $this->calls === 1) {
            touch($this->signal);
        }
        usleep($this->millis * 1000);
        if (!preg_match('/FACTS:\n(.*?)\n\nOUTPUT FORMAT/s', $prompt, $m)) {
            return null;
        }
        $blocks = [];
        foreach (json_decode($m[1], true)['blocks'] ?? [] as $block) {
            $blocks[] = ['key' => $block['key'], 'text' => 'The coach says this is about '
                . implode(' and ', $block['required_tokens'] ?? [])];
        }
        return json_encode(['blocks' => $blocks]);
    }

    #[\Override]
    public function last_tokens(): ?int {
        return 10;
    }
}
