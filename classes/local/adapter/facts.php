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

namespace mod_sideline\local\adapter;

/**
 * Module-specific facts about one course module for one user. Every field may be unknown (null).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class facts {
    /**
     * Constructor.
     *
     * @param int|null $opensat When the module opens for this user (after overrides).
     * @param int|null $deadline When work is due for this user (after overrides).
     * @param int|null $closesat When the module stops accepting work (cut-off, close time).
     * @param int|null $attemptsused Attempts started or submitted.
     * @param int|null $attemptsallowed Attempts allowed, 0 for unlimited.
     * @param bool|null $submitted Whether work is submitted and waiting or graded.
     */
    public function __construct(
        /** @var int|null Opening time. */
        public readonly ?int $opensat = null,
        /** @var int|null Due date. */
        public readonly ?int $deadline = null,
        /** @var int|null Closing time. */
        public readonly ?int $closesat = null,
        /** @var int|null Attempts used. */
        public readonly ?int $attemptsused = null,
        /** @var int|null Attempts allowed, 0 unlimited. */
        public readonly ?int $attemptsallowed = null,
        /** @var bool|null Submitted. */
        public readonly ?bool $submitted = null,
    ) {
    }

    /**
     * A sanitised copy: negative or absurd values become unknown.
     *
     * @return self
     */
    public function sanitised(): self {
        $time = static fn(?int $t): ?int => ($t !== null && $t > 0 && $t < 4102444800) ? $t : null;
        $count = static fn(?int $n): ?int => ($n !== null && $n >= 0 && $n < 100000) ? $n : null;
        return new self(
            $time($this->opensat),
            $time($this->deadline),
            $time($this->closesat),
            $count($this->attemptsused),
            $count($this->attemptsallowed),
            $this->submitted,
        );
    }
}
