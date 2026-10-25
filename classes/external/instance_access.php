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

namespace mod_sideline\external;

/**
 * Shared access checks of all web services: login, enrolment or guest access, visibility of the
 * activity, module capability. Every service acts only for the calling user.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait instance_access {
    /**
     * Resolve and check a Sideline course module for the calling user.
     *
     * @param int $cmid Course module id.
     * @return array [course, cm, context, instance record]
     */
    protected static function access(int $cmid): array {
        global $DB;

        [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'sideline');
        // Login, enrolment/guest access and activity visibility are enforced here, not only by the context check.
        require_login($course, false, $cm, false, true);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/sideline:view', $context);
        $sideline = $DB->get_record('sideline', ['id' => $cm->instance], '*', MUST_EXIST);
        return [$course, $cm, $context, $sideline];
    }
}
