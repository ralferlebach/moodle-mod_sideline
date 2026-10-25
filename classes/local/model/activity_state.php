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
 * The state of one observed activity for one user (P-01).
 *
 * Every dimension is kept separately and may be unknown. Unknown is never treated as negative
 * (P-01): a rule that needs "not passed" must ask for pass === NO, never for pass !== YES.
 * Usage (lastused, from the log), completion, grade and passing are different dimensions and are
 * never derived from each other (P1-04).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class activity_state {
    /** @var string Dimension value: known not to hold. */
    public const NO = 'no';
    /** @var string Dimension value: known to hold. */
    public const YES = 'yes';
    /** @var string Dimension value: the value could not be determined. */
    public const UNKNOWN = 'unknown';

    /** @var string Lock reason: a date condition. */
    public const LOCK_DATE = 'date';
    /** @var string Lock reason: completion of other activities. */
    public const LOCK_COMPLETION = 'completion';
    /** @var string Lock reason: a grade condition. */
    public const LOCK_GRADE = 'grade';
    /** @var string Lock reason: group or grouping membership. */
    public const LOCK_GROUP = 'group';
    /** @var string Lock reason: anything else or a combination. */
    public const LOCK_OTHER = 'other';

    /**
     * Constructor.
     *
     * @param int $cmid Course module id.
     * @param string $label Display name of the activity (formatted, plain text).
     * @param bool $visible Whether the user may see the activity at all.
     * @param string $available yes, no or unknown: whether the user may open it now (core availability).
     * @param string $completion yes, no or unknown.
     * @param string $pass yes, no or unknown.
     * @param float|null $grade Final grade in percent, null when unknown or hidden.
     * @param int|null $deadline Due date as unix time.
     * @param int|null $opensat Opening time of a locked or not yet open activity.
     * @param int|null $lastactivity Last change of completion or grade (not usage).
     * @param string $dataquality ok, partial or unknown.
     * @param int $courseid Course of the activity.
     * @param string $modname Module name.
     * @param int|null $lastused Last view or use by the user, from the log.
     * @param int|null $closesat When the activity stops accepting work.
     * @param int|null $attemptsused Attempts used.
     * @param int|null $attemptsallowed Attempts allowed, 0 unlimited.
     * @param string $submitted yes, no or unknown.
     * @param string|null $lockreason One of the LOCK_ constants when available is NO.
     */
    public function __construct(
        /** @var int Course module id. */
        public readonly int $cmid,
        /** @var string Display name. */
        public readonly string $label,
        /** @var bool Visible. */
        public readonly bool $visible,
        /** @var string Availability. */
        public readonly string $available = self::UNKNOWN,
        /** @var string Completion. */
        public readonly string $completion = self::UNKNOWN,
        /** @var string Pass. */
        public readonly string $pass = self::UNKNOWN,
        /** @var float|null Grade in percent. */
        public readonly ?float $grade = null,
        /** @var int|null Due date. */
        public readonly ?int $deadline = null,
        /** @var int|null Opening time. */
        public readonly ?int $opensat = null,
        /** @var int|null Last completion or grade change. */
        public readonly ?int $lastactivity = null,
        /** @var string Data quality. */
        public readonly string $dataquality = 'ok',
        /** @var int Course id. */
        public readonly int $courseid = 0,
        /** @var string Module name. */
        public readonly string $modname = '',
        /** @var int|null Last use. */
        public readonly ?int $lastused = null,
        /** @var int|null Closing time. */
        public readonly ?int $closesat = null,
        /** @var int|null Attempts used. */
        public readonly ?int $attemptsused = null,
        /** @var int|null Attempts allowed. */
        public readonly ?int $attemptsallowed = null,
        /** @var string Submitted. */
        public readonly string $submitted = self::UNKNOWN,
        /** @var string|null Lock reason. */
        public readonly ?string $lockreason = null,
    ) {
    }

    /**
     * Whether another attempt is known to be possible.
     *
     * @return string yes, no or unknown.
     */
    public function attempts_left(): string {
        if ($this->attemptsallowed === null || $this->attemptsused === null) {
            return self::UNKNOWN;
        }
        if ($this->attemptsallowed === 0) {
            return self::YES;
        }
        return $this->attemptsused < $this->attemptsallowed ? self::YES : self::NO;
    }

    /**
     * A stable representation, used in traces and tests.
     *
     * @return array
     */
    public function to_fingerprint_array(): array {
        return [
            $this->cmid, $this->visible ? 1 : 0, $this->available, $this->completion, $this->pass,
            $this->grade === null ? 'n' : round($this->grade, 1), $this->deadline ?? 0, $this->opensat ?? 0,
            $this->closesat ?? 0, $this->attemptsused ?? 'n', $this->attemptsallowed ?? 'n', $this->submitted,
            $this->lockreason ?? '', $this->lastused ? intdiv($this->lastused, DAYSECS) : 0,
        ];
    }

    /**
     * The state as a plain array, for the administrator debug export.
     *
     * @return array
     */
    public function to_array(): array {
        return get_object_vars($this);
    }
}
