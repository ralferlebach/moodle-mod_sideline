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

namespace mod_sideline\local;

use mod_sideline\local\cache\commentary_cache;

/**
 * Observers: mark cached commentary dirty, nothing else (P-29).
 *
 * Observers never recompute. Recomputation happens on the next request that needs the text.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * A change that concerns one user: completion or grade.
     *
     * @param \core\event\base $event The event.
     */
    public static function user_changed(\core\event\base $event): void {
        $userid = (int) $event->relateduserid;
        $courseid = (int) $event->courseid;
        if ($userid > 0 && $courseid > 0) {
            commentary_cache::mark_user_dirty($courseid, $userid);
        }
    }

    /**
     * A change to the structure of a course: activities and sections.
     *
     * @param \core\event\base $event The event.
     */
    public static function course_changed(\core\event\base $event): void {
        if ((int) $event->courseid > 0) {
            commentary_cache::mark_course_dirty((int) $event->courseid);
        }
    }

    /**
     * Group membership changed: the member's own text and, in group mode, every text of the course.
     *
     * @param \core\event\base $event The event.
     */
    public static function group_changed(\core\event\base $event): void {
        if ((int) $event->courseid > 0) {
            commentary_cache::mark_course_dirty((int) $event->courseid);
        }
    }

    /**
     * A user profile changed: the first name may be part of AI texts.
     *
     * @param \core\event\base $event The event.
     */
    public static function user_renamed(\core\event\base $event): void {
        global $DB;

        $userid = (int) $event->relateduserid ?: (int) $event->objectid;
        if ($userid > 0) {
            $DB->execute('UPDATE {sideline_cache} SET dirty = 1, stamp = stamp + 1 WHERE userid = ?', [$userid]);
        }
    }
}
