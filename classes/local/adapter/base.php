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
 * Contract of a slcmod_* adapter subplugin (P1-06).
 *
 * An adapter adds module-specific facts (opening and closing times, attempts, submission state)
 * to the core state. It is a read-only data source: it never decides, never writes, never sees any
 * course module the user cannot already see, and its answers are type-checked by the registry.
 * The core state remains valid without any adapter; a missing or failing adapter means the
 * module-specific dimensions stay unknown.
 *
 * Adapters must answer for all given course modules at once, with a bounded number of queries
 * (no query per course module).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base {
    /**
     * The module this adapter describes, for example "assign".
     *
     * @return string
     */
    abstract public function modname(): string;

    /**
     * Collect the module-specific facts for one user.
     *
     * @param \cm_info[] $cms Course modules of this module type that the user can see, keyed by id.
     * @param int $userid User id.
     * @param int $now Current time.
     * @return facts[] Keyed by course module id. Missing keys mean "nothing known".
     */
    abstract public function collect(array $cms, int $userid, int $now): array;

    /**
     * Events after which cached commentary of the related user is stale. Optional.
     *
     * @return string[] Fully qualified event class names.
     */
    public function invalidating_events(): array {
        return [];
    }
}
