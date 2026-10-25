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

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_sideline\local\consent\consent_manager;
use mod_sideline\local\dialog\dialog_manager;

/**
 * Webservice answer_question: the calling user answers or skips an interview question (P1-08).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class answer_question extends external_api {
    use instance_access;

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'questionid' => new external_value(PARAM_INT, 'Question id'),
            'value' => new external_value(PARAM_RAW, 'Answer value: option index, number, text or JSON list', VALUE_DEFAULT, ''),
            'skip' => new external_value(PARAM_BOOL, 'Skip the question', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * Store the answer.
     *
     * @param int $cmid Course module id.
     * @param int $questionid Question id.
     * @param string $value Value.
     * @param bool $skip Skip.
     * @return array
     */
    public static function execute(int $cmid, int $questionid, string $value = '', bool $skip = false): array {
        global $USER;

        $p = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid, 'questionid' => $questionid, 'value' => $value, 'skip' => $skip,
        ]);
        [, , , $sideline] = self::access($p['cmid']);
        if (!consent_manager::allows_processing($sideline, (int) $USER->id) || empty($sideline->dialogenabled)) {
            throw new \moodle_exception('err_dialognotallowed', 'mod_sideline');
        }
        dialog_manager::answer($sideline, (int) $USER->id, $p['questionid'], $p['value'], $p['skip'], time());
        return ['saved' => true];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure(['saved' => new external_value(PARAM_BOOL, 'Saved')]);
    }
}
