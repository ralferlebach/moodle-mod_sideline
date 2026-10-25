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
use mod_sideline\local\group\sharing_manager;
use mod_sideline\local\instance_config;

/**
 * Webservice set_group_sharing: the calling user opts in to or out of group sharing (L-G05, L-G06).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class set_group_sharing extends external_api {
    use instance_access;

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'publish' => new external_value(PARAM_BOOL, 'Share my completions'),
            'named' => new external_value(PARAM_BOOL, 'My first name may be shown', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * Record.
     *
     * @param int $cmid Course module id.
     * @param bool $publish Share.
     * @param bool $named Show name.
     * @return array
     */
    public static function execute(int $cmid, bool $publish, bool $named = false): array {
        global $USER;

        $p = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid, 'publish' => $publish, 'named' => $named]);
        [, , $context, $sideline] = self::access($p['cmid']);
        if ($sideline->sharingmode === instance_config::GROUP_INDIVIDUAL && $p['publish']) {
            throw new \moodle_exception('err_nosharingmode', 'mod_sideline');
        }
        if ($p['publish'] && !\mod_sideline\local\consent\consent_manager::allows_processing($sideline, (int) $USER->id)) {
            throw new \moodle_exception('err_sharingconsent', 'mod_sideline');
        }
        $named = $p['named'] && in_array($sideline->grouppublish, ['named', 'named_with_group'], true);
        $status = sharing_manager::record($sideline, (int) $USER->id, $p['publish'], $named, $context->id, time());
        return ['publish' => $status['publish'], 'named' => $status['named']];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'publish' => new external_value(PARAM_BOOL, 'Shares completions'),
            'named' => new external_value(PARAM_BOOL, 'Name may be shown'),
        ]);
    }
}
