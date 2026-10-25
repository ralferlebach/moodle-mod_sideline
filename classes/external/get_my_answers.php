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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_sideline\local\dialog\dialog_manager;

/**
 * Webservice get_my_answers: the calling user's own answers and their effects (transparency, P1-08).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_my_answers extends external_api {
    use instance_access;

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(['cmid' => new external_value(PARAM_INT, 'Course module id')]);
    }

    /**
     * Get the answers.
     *
     * @param int $cmid Course module id.
     * @return array
     */
    public static function execute(int $cmid): array {
        global $USER;

        $p = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);
        [, , , $sideline] = self::access($p['cmid']);
        $answers = [];
        foreach (dialog_manager::my_answers($sideline, (int) $USER->id) as $a) {
            $answers[] = [
                'questionid' => $a['questionid'],
                'prompt' => format_string($a['prompt']),
                'answer' => $a['display'],
                'skipped' => $a['skipped'],
                'effect' => get_string('effect_' . $a['effect'], 'mod_sideline'),
            ];
        }
        return ['answers' => $answers];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'answers' => new external_multiple_structure(new external_single_structure([
                'questionid' => new external_value(PARAM_INT, 'Question id'),
                'prompt' => new external_value(PARAM_TEXT, 'Question'),
                'answer' => new external_value(PARAM_TEXT, 'The answer as the learner gave it'),
                'skipped' => new external_value(PARAM_BOOL, 'Skipped'),
                'effect' => new external_value(PARAM_TEXT, 'What the answer changes in the commentary'),
            ])),
        ]);
    }
}
