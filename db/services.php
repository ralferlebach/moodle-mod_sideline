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
 * External functions of mod_sideline.
 *
 * Every function acts only for the calling user. There is no function that returns data of another
 * user, and none that lets teachers read commentary or consent decisions (L-16, P-40).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'mod_sideline_get_commentary' => [
        'classname' => 'mod_sideline\external\get_commentary',
        'description' => 'Get the commentary of the calling user for a Sideline activity.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/sideline:view',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'mod_sideline_set_consent' => [
        'classname' => 'mod_sideline\external\set_consent',
        'description' => 'Accept, decline or withdraw consent of the calling user for a Sideline activity.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/sideline:view',
    ],
    'mod_sideline_answer_question' => [
        'classname' => 'mod_sideline\external\answer_question',
        'description' => 'Answer or skip an interview question as the calling user.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/sideline:view',
    ],
    'mod_sideline_get_my_answers' => [
        'classname' => 'mod_sideline\external\get_my_answers',
        'description' => 'Get the own interview answers of the calling user and their effects.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/sideline:view',
    ],
    'mod_sideline_delete_answer' => [
        'classname' => 'mod_sideline\external\delete_answer',
        'description' => 'Delete one of the own answers of the calling user.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/sideline:view',
    ],
    'mod_sideline_set_persona' => [
        'classname' => 'mod_sideline\external\set_persona',
        'description' => 'Choose one of the offered personas as the calling user.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/sideline:view',
    ],
    'mod_sideline_set_group_sharing' => [
        'classname' => 'mod_sideline\external\set_group_sharing',
        'description' => 'Opt in to or out of group sharing as the calling user.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/sideline:view',
    ],
];
