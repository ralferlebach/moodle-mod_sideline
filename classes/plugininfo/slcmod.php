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

namespace mod_sideline\plugininfo;

/**
 * Plugin info of the slcmod_* adapter subplugins (P1-06).
 *
 * Adapters are always enabled when installed: an adapter only adds read-only facts, and removing
 * it makes the facts unknown, never wrong.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class slcmod extends \core\plugininfo\base {
    #[\Override]
    public function is_uninstall_allowed() {
        return true;
    }

    #[\Override]
    public static function get_enabled_plugins() {
        return null;
    }
}
