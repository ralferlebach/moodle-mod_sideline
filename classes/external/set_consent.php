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

/**
 * Webservice set_consent: the calling user accepts, declines or withdraws consent for one activity.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class set_consent extends external_api {
    use instance_access;

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id of the Sideline activity'),
            'accept' => new external_value(PARAM_BOOL, 'True to accept, false to decline or withdraw'),
        ]);
    }

    /**
     * Record the decision of the calling user.
     *
     * @param int $cmid Course module id.
     * @param bool $accept Accept or not.
     * @return array
     */
    public static function execute(int $cmid, bool $accept): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid, 'accept' => $accept]);
        [, , $context, $sideline] = self::access($params['cmid']);
        $status = consent_manager::record_decision($sideline, (int) $USER->id, (bool) $params['accept'], $context->id, time());
        return ['status' => $status];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHAEXT, 'The new consent status'),
        ]);
    }
}
