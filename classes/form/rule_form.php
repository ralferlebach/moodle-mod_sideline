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
 * Teacher form for one custom rule: a JSON definition, validated against the rule schema, with a
 * preview on synthetic sample data (never on learner data).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rule_form extends \moodleform {
    #[\Override]
    protected function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'ruleid');
        $mform->setType('ruleid', PARAM_INT);
        $mform->addElement('hidden', 'action', 'edit');
        $mform->setType('action', PARAM_ALPHA);

        $mform->addElement(
            'textarea',
            'definition',
            get_string('ruledefinition', 'mod_sideline'),
            ['rows' => 18, 'cols' => 80, 'class' => 'text-monospace']
        );
        $mform->setType('definition', PARAM_RAW);
        $mform->addHelpButton('definition', 'ruledefinition', 'mod_sideline');
        $mform->addElement('advcheckbox', 'enabled', get_string('enabled', 'mod_sideline'));
        $mform->setDefault('enabled', 1);

        $scenarios = [];
        foreach (['none_done', 'half_done', 'deadline_tomorrow', 'all_done'] as $s) {
            $scenarios[$s] = get_string('scenario_' . $s, 'mod_sideline');
        }
        $mform->addElement('select', 'scenario', get_string('previewscenario', 'mod_sideline'), $scenarios);
        $buttons = [
            $mform->createElement('submit', 'previewbutton', get_string('preview')),
            $mform->createElement('submit', 'submitbutton', get_string('savechanges')),
            $mform->createElement('cancel'),
        ];
        $mform->addGroup($buttons, 'buttonar', '', [' '], false);
        $mform->closeHeaderBefore('buttonar');
    }

    #[\Override]
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $decoded = json_decode((string) $data['definition'], true);
        [$clean, $problems] = \mod_sideline\local\rules\rule_schema::validate($decoded, $this->_customdata['cmids']);
        if ($clean === null) {
            $errors['definition'] = implode(' ', $problems);
        }
        return $errors;
    }
}
