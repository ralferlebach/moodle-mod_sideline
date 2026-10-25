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

namespace mod_sideline\local\model;

/**
 * The result of one rule: what to say and which action may be offered (P-04).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class recommendation {
    /** @var int Category: deadlines and access (highest precedence, P-06). */
    public const CAT_SAFETY = 1;
    /** @var int Category: concrete next steps. */
    public const CAT_NEXT = 2;
    /** @var int Category: successes. */
    public const CAT_SUCCESS = 3;
    /** @var int Category: orientation. */
    public const CAT_ORIENTATION = 4;
    /** @var int Category: small talk, the last resort. */
    public const CAT_SMALLTALK = 5;

    /** @var string Action: link to an activity the user may open. */
    public const ACTION_OPEN = 'open';
    /** @var string Action: no action offered. */
    public const ACTION_NONE = 'none';

    /**
     * Constructor.
     *
     * @param string $key Stable rule key, also the key of the template string.
     * @param int $category One of the CAT_ constants.
     * @param int $priority Order within the category, lower first.
     * @param string $actiontype One of the ACTION_ constants.
     * @param int|null $targetcmid Course module the action points to.
     * @param array $facts Typed facts for the template: label, count, deadline and so on.
     * @param int|null $expiresat Time at which the recommendation stops being valid.
     */
    public function __construct(
        /** @var string Rule key. */
        public readonly string $key,
        /** @var int Category. */
        public readonly int $category,
        /** @var int Priority within the category. */
        public readonly int $priority,
        /** @var string Action type. */
        public readonly string $actiontype = self::ACTION_NONE,
        /** @var int|null Target course module. */
        public readonly ?int $targetcmid = null,
        /** @var array Facts for the template. */
        public readonly array $facts = [],
        /** @var int|null Expiry. */
        public readonly ?int $expiresat = null,
    ) {
    }
}
