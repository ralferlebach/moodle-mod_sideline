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

use mod_sideline\local\model\activity_state;

/**
 * Everything besides the activity states that rules may look at.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class evaluation_context {
    /**
     * Constructor.
     *
     * @param string[] $effects Effects of the learner's interview answers.
     * @param string $coursecomplete Course completion of the instance's course: yes, no or unknown.
     * @param array[] $customrules Validated custom rule definitions.
     * @param array $hits Rule key to times shown (newest first).
     * @param string[] $currentkeys Rule keys of the commentary currently shown.
     * @param array[] $groupfacts Group statements prepared by the group service.
     */
    public function __construct(
        /** @var string[] Effects. */
        public readonly array $effects = [],
        /** @var string Course completion. */
        public readonly string $coursecomplete = activity_state::UNKNOWN,
        /** @var array[] Custom rules. */
        public readonly array $customrules = [],
        /** @var array Rule hits. */
        public readonly array $hits = [],
        /** @var string[] Current keys. */
        public readonly array $currentkeys = [],
        /** @var array[] Group facts. */
        public readonly array $groupfacts = [],
    ) {
    }

    /**
     * Whether an effect is active.
     *
     * @param string $effect Effect name.
     * @return bool
     */
    public function has_effect(string $effect): bool {
        return in_array($effect, $this->effects, true);
    }
}
