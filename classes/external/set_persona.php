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
use mod_sideline\local\persona\persona_resolver;

/**
 * Webservice set_persona: the calling user chooses one of the offered personas (P1-07).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class set_persona extends external_api {
    use instance_access;

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'persona' => new external_value(PARAM_ALPHANUMEXT, 'Persona key, empty for the default', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Choose.
     *
     * @param int $cmid Course module id.
     * @param string $persona Persona key.
     * @return array
     */
    public static function execute(int $cmid, string $persona = ''): array {
        global $USER;

        $p = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid, 'persona' => $persona]);
        [, , , $sideline] = self::access($p['cmid']);
        return ['persona' => persona_resolver::choose($sideline, (int) $USER->id, $p['persona'])];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure(['persona' => new external_value(PARAM_ALPHANUMEXT, 'Persona in effect')]);
    }
}
