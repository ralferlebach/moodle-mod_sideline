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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Site administrator form for a debug export: scope, purpose and time window are mandatory.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class debug_form extends \moodleform {
    #[\Override]
    protected function definition() {
        $mform = $this->_form;
        $mform->addElement('static', 'notice', '', get_string('debug_notice', 'mod_sideline'));
        $mform->addElement('text', 'cmid', get_string('debug_cmid', 'mod_sideline'));
        $mform->setType('cmid', PARAM_INT);
        $mform->addRule('cmid', null, 'required');
        $mform->addElement('autocomplete', 'userid', get_string('user'), [], [
            'ajax' => 'core_user/form_user_selector', 'multiple' => false,
        ]);
        $mform->addRule('userid', null, 'required');
        $mform->addElement('textarea', 'purpose', get_string('debug_purpose', 'mod_sideline'), ['rows' => 3, 'cols' => 60]);
        $mform->setType('purpose', PARAM_TEXT);
        $mform->addRule('purpose', null, 'required');
        $mform->addElement('date_time_selector', 'timefrom', get_string('debug_from', 'mod_sideline'));
        $mform->setDefault('timefrom', time() - WEEKSECS);
        $mform->addElement('date_time_selector', 'timeto', get_string('debug_to', 'mod_sideline'));
        $this->add_action_buttons(false, get_string('debug_download', 'mod_sideline'));
    }

    #[\Override]
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (mb_strlen(trim((string) $data['purpose'])) < 10) {
            $errors['purpose'] = get_string('err_debugpurpose', 'mod_sideline');
        }
        if ((int) $data['timeto'] < (int) $data['timefrom']) {
            $errors['timeto'] = get_string('err_debugwindow', 'mod_sideline');
        }
        return $errors;
    }
}
