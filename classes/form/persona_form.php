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

use mod_sideline\local\persona\persona_manager;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Administrator form for a site persona. Saving always creates a new draft version.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class persona_form extends \moodleform {
    #[\Override]
    protected function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'action', 'edit');
        $mform->setType('action', PARAM_ALPHA);
        $mform->addElement('text', 'shortname', get_string('personashortname', 'mod_sideline'));
        $mform->setType('shortname', PARAM_ALPHANUMEXT);
        $mform->addHelpButton('shortname', 'personashortname', 'mod_sideline');
        $mform->addElement('text', 'name', get_string('name'));
        $mform->setType('name', PARAM_TEXT);
        $mform->addElement('textarea', 'style', get_string('personastyle', 'mod_sideline'), ['rows' => 6, 'cols' => 70]);
        $mform->setType('style', PARAM_TEXT);
        $mform->addHelpButton('style', 'personastyle', 'mod_sideline');
        $mform->addElement('text', 'opener', get_string('personaopener', 'mod_sideline'), ['size' => 60]);
        $mform->setType('opener', PARAM_TEXT);
        $mform->addElement('static', 'safety', '', get_string('personasafety', 'mod_sideline'));
        $this->add_action_buttons();
    }

    #[\Override]
    public function validation($data, $files) {
        return array_merge(parent::validation($data, $files), persona_manager::validate((object) $data, (int) $data['id']));
    }
}
