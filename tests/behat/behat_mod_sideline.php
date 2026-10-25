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
 * Behat steps and page resolution of mod_sideline.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

/**
 * Behat context of mod_sideline.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_mod_sideline extends behat_base {
    /**
     * Pages of an instance: "View", "Questions", "Rules", "Transfer".
     *
     * @param string $type Page type.
     * @param string $identifier Activity name or idnumber.
     * @return moodle_url
     */
    protected function resolve_page_instance_url(string $type, string $identifier): moodle_url {
        $cm = $this->get_cm_by_activity_name('sideline', $identifier);
        $pages = ['view' => 'view', 'questions' => 'questions', 'rules' => 'rules', 'transfer' => 'transfer'];
        $key = strtolower($type);
        if (!isset($pages[$key])) {
            throw new Exception('Unknown mod_sideline page type "' . $type . '"');
        }
        return new moodle_url('/mod/sideline/' . $pages[$key] . '.php', ['id' => $cm->id]);
    }
}
