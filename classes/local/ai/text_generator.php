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
 * The single seam between mod_sideline and an AI provider.
 *
 * Production uses core_text_generator, which goes through Moodle's AI subsystem and therefore
 * through its provider configuration. Tests use a fake. There is deliberately no implementation
 * that talks to an external service by itself (P-20).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface text_generator {
    /**
     * Whether the Moodle AI subsystem can currently serve text generation for this user and context.
     *
     * Checks the user policy acceptance, that the action is available for at least one enabled
     * provider, and that it is enabled in the module context (P-44). Does not check the activity's
     * own switches; the gateway does that.
     *
     * @param int $userid User id.
     * @param \context $context Module context.
     * @return bool
     */
    public function is_available(int $userid, \context $context): bool;

    /**
     * Generate text for a prompt.
     *
     * @param string $prompt The complete prompt, already pseudonymised.
     * @param int $userid User id.
     * @param int $contextid Module context id.
     * @return string|null The generated text, or null on any failure, timeout, rate limit or empty result.
     */
    public function generate(string $prompt, int $userid, int $contextid): ?string;
}
