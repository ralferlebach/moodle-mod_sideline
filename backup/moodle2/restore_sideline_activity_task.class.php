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
 * Restore task of mod_sideline.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/sideline/backup/moodle2/restore_sideline_stepslib.php');

/**
 * Restore task.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_sideline_activity_task extends restore_activity_task {
    #[\Override]
    protected function define_my_settings() {
    }

    #[\Override]
    protected function define_my_steps() {
        $this->add_step(new restore_sideline_activity_structure_step('sideline_structure', 'sideline.xml'));
    }

    #[\Override]
    public static function define_decode_contents() {
        return [];
    }

    #[\Override]
    public static function define_decode_rules() {
        return [];
    }

    #[\Override]
    public static function define_restore_log_rules() {
        return [];
    }
}
