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
 * Event observers of mod_sideline. They only mark cached commentary dirty (P-29).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\course_module_completion_updated',
        'callback' => '\mod_sideline\local\observer::user_changed',
    ],
    [
        'eventname' => '\core\event\user_graded',
        'callback' => '\mod_sideline\local\observer::user_changed',
    ],
    [
        'eventname' => '\core\event\course_module_created',
        'callback' => '\mod_sideline\local\observer::course_changed',
    ],
    [
        'eventname' => '\core\event\course_module_updated',
        'callback' => '\mod_sideline\local\observer::course_changed',
    ],
    [
        'eventname' => '\core\event\course_module_deleted',
        'callback' => '\mod_sideline\local\observer::course_changed',
    ],
    [
        'eventname' => '\core\event\course_section_updated',
        'callback' => '\mod_sideline\local\observer::course_changed',
    ],
    [
        'eventname' => '\core\event\user_enrolment_created',
        'callback' => '\mod_sideline\local\observer::user_changed',
    ],
    [
        'eventname' => '\core\event\user_enrolment_updated',
        'callback' => '\mod_sideline\local\observer::user_changed',
    ],
    [
        'eventname' => '\core\event\user_enrolment_deleted',
        'callback' => '\mod_sideline\local\observer::user_changed',
    ],
    [
        'eventname' => '\core\event\role_assigned',
        'callback' => '\mod_sideline\local\observer::user_changed',
    ],
    [
        'eventname' => '\core\event\role_unassigned',
        'callback' => '\mod_sideline\local\observer::user_changed',
    ],
    [
        'eventname' => '\core\event\group_member_added',
        'callback' => '\mod_sideline\local\observer::group_changed',
    ],
    [
        'eventname' => '\core\event\group_member_removed',
        'callback' => '\mod_sideline\local\observer::group_changed',
    ],
    [
        'eventname' => '\core\event\group_updated',
        'callback' => '\mod_sideline\local\observer::course_changed',
    ],
    [
        'eventname' => '\core\event\group_deleted',
        'callback' => '\mod_sideline\local\observer::course_changed',
    ],
    [
        'eventname' => '\core\event\grouping_group_assigned',
        'callback' => '\mod_sideline\local\observer::course_changed',
    ],
    [
        'eventname' => '\core\event\grouping_group_unassigned',
        'callback' => '\mod_sideline\local\observer::course_changed',
    ],
    [
        'eventname' => '\core\event\course_completed',
        'callback' => '\mod_sideline\local\observer::user_changed',
    ],
    [
        'eventname' => '\core\event\course_restored',
        'callback' => '\mod_sideline\local\observer::course_changed',
    ],
    [
        'eventname' => '\core\event\course_reset_ended',
        'callback' => '\mod_sideline\local\observer::course_changed',
    ],
    [
        'eventname' => '\core\event\user_updated',
        'callback' => '\mod_sideline\local\observer::user_renamed',
    ],
];
