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

namespace mod_sideline\form;

use mod_sideline\local\transfer\config_transfer;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Import form: file, selected parts, conflict handling.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class transfer_form extends \moodleform {
    #[\Override]
    protected function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $mform->addElement(
            'filepicker',
            'importfile',
            get_string('importfile', 'mod_sideline'),
            null,
            ['accepted_types' => ['.json'], 'maxbytes' => 512 * 1024]
        );
        $mform->addRule('importfile', null, 'required');
        foreach (config_transfer::PARTS as $part) {
            $mform->addElement('advcheckbox', 'part_' . $part, get_string('transferpart_' . $part, 'mod_sideline'));
            $mform->setDefault('part_' . $part, 1);
        }
        $mform->addElement('select', 'conflict', get_string('transferconflict', 'mod_sideline'), [
            'replace' => get_string('transferconflict_replace', 'mod_sideline'),
            'skip' => get_string('transferconflict_skip', 'mod_sideline'),
            'rename' => get_string('transferconflict_rename', 'mod_sideline'),
        ]);
        $mform->addElement('advcheckbox', 'confirm', get_string('transferconfirm', 'mod_sideline'));
        $mform->addHelpButton('confirm', 'transferconfirm', 'mod_sideline');
        $this->add_action_buttons(true, get_string('import'));
    }
}
