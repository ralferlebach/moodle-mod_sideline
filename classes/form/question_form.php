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

use mod_sideline\local\dialog\question_type;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Teacher form for one interview question. Answers are never shown here.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_form extends \moodleform {
    #[\Override]
    protected function definition() {
        $mform = $this->_form;
        $triggers = $this->_customdata['triggers'];

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'qid');
        $mform->setType('qid', PARAM_INT);
        $mform->addElement('hidden', 'action', 'edit');
        $mform->setType('action', PARAM_ALPHA);

        // Only the types the site policy allows can be chosen (P-31). The current type of an existing question
        // stays selectable, so it can be edited or disabled without changing its type (and losing its answers).
        $allowed = \mod_sideline\local\dialog\dialog_manager::allowed_types();
        $current = (string) ($this->_customdata['currenttype'] ?? '');
        $types = [];
        foreach (question_type::TYPES as $type) {
            if (in_array($type, $allowed, true) || $type === $current) {
                $types[$type] = get_string('qtype_' . $type, 'mod_sideline');
            }
        }
        if (!$types) {
            $mform->addElement('static', 'notypes', '', get_string('questions_notypes', 'mod_sideline'));
        }
        $mform->addElement('select', 'qtype', get_string('qtype', 'mod_sideline'), $types);
        $mform->addElement('textarea', 'prompt', get_string('qprompt', 'mod_sideline'), ['rows' => 3, 'cols' => 60]);
        $mform->setType('prompt', PARAM_TEXT);
        $mform->addRule('prompt', null, 'required', null, 'client');
        $mform->addElement('select', 'triggerkey', get_string('qtrigger', 'mod_sideline'), $triggers);
        $mform->addHelpButton('triggerkey', 'qtrigger', 'mod_sideline');
        $mform->addElement('duration', 'cooldown', get_string('qcooldown', 'mod_sideline'), ['optional' => false]);
        $mform->addHelpButton('cooldown', 'qcooldown', 'mod_sideline');
        $mform->addElement('advcheckbox', 'enabled', get_string('enabled', 'mod_sideline'));
        $mform->setDefault('enabled', 1);

        $effects = [];
        foreach (question_type::EFFECTS as $effect) {
            $effects[$effect] = get_string('effect_' . $effect, 'mod_sideline');
        }
        $mform->addElement('static', 'effectshelp', '', get_string('qeffects_help', 'mod_sideline'));

        // Multiple choice: one option per line, "label | effect".
        $mform->addElement('textarea', 'mcoptions', get_string('qmcoptions', 'mod_sideline'), ['rows' => 5, 'cols' => 60]);
        $mform->setType('mcoptions', PARAM_TEXT);
        $mform->addHelpButton('mcoptions', 'qmcoptions', 'mod_sideline');
        $mform->hideIf('mcoptions', 'qtype', 'neq', question_type::MC);

        // Free text.
        $mform->addElement('text', 'maxlength', get_string('qmaxlength', 'mod_sideline'), ['size' => 5]);
        $mform->setType('maxlength', PARAM_INT);
        $mform->setDefault('maxlength', 500);
        $mform->hideIf('maxlength', 'qtype', 'neq', question_type::TEXT);
        $mform->addElement('static', 'texthint', '', get_string('qtext_hint', 'mod_sideline'));
        $mform->hideIf('texthint', 'qtype', 'neq', question_type::TEXT);

        // Slider and semantic differential share the threshold and effects.
        foreach (['min' => 0, 'max' => 10, 'step' => 1] as $name => $default) {
            $mform->addElement('text', 'slider' . $name, get_string('qslider' . $name, 'mod_sideline'), ['size' => 5]);
            $mform->setType('slider' . $name, PARAM_INT);
            $mform->setDefault('slider' . $name, $default);
            $mform->hideIf('slider' . $name, 'qtype', 'neq', question_type::SLIDER);
        }
        foreach (['minlabel', 'maxlabel'] as $name) {
            $mform->addElement('text', 'slider' . $name, get_string('qslider' . $name, 'mod_sideline'));
            $mform->setType('slider' . $name, PARAM_TEXT);
            $mform->hideIf('slider' . $name, 'qtype', 'neq', question_type::SLIDER);
        }
        $mform->addElement('textarea', 'pairs', get_string('qpairs', 'mod_sideline'), ['rows' => 4, 'cols' => 60]);
        $mform->setType('pairs', PARAM_TEXT);
        $mform->addHelpButton('pairs', 'qpairs', 'mod_sideline');
        $mform->hideIf('pairs', 'qtype', 'neq', question_type::SEMDIFF);
        $mform->addElement('select', 'scale', get_string('qscale', 'mod_sideline'), [5 => 5, 7 => 7]);
        $mform->setDefault('scale', 7);
        $mform->hideIf('scale', 'qtype', 'neq', question_type::SEMDIFF);

        $mform->addElement('text', 'threshold', get_string('qthreshold', 'mod_sideline'), ['size' => 5]);
        $mform->setType('threshold', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('threshold', 'qthreshold', 'mod_sideline');
        $mform->addElement('select', 'below', get_string('qbelow', 'mod_sideline'), $effects);
        $mform->addElement('select', 'atorabove', get_string('qatorabove', 'mod_sideline'), $effects);
        foreach (['threshold', 'below', 'atorabove'] as $name) {
            $mform->hideIf($name, 'qtype', 'in', [question_type::MC, question_type::TEXT]);
        }

        $this->add_action_buttons();
    }

    #[\Override]
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (empty($data['qtype']) || !in_array($data['qtype'], question_type::TYPES, true)) {
            $errors['qtype'] = get_string('questions_notypes', 'mod_sideline');
            return $errors;
        }
        [, $problems] = question_type::normalise_config($data['qtype'], self::config_from($data));
        if ($problems) {
            $errors['qtype'] = implode(' ', $problems);
        }
        if ($data['threshold'] !== '' && !is_numeric($data['threshold'])) {
            $errors['threshold'] = get_string('err_q_threshold', 'mod_sideline');
        }
        return $errors;
    }

    /**
     * The question configuration from form data.
     *
     * @param array $data Form data.
     * @return array
     */
    public static function config_from(array $data): array {
        $lines = static fn(string $text): array => array_values(array_filter(array_map('trim', preg_split('/\R/', $text))));
        switch ($data['qtype']) {
            case question_type::MC:
                $options = [];
                foreach ($lines((string) ($data['mcoptions'] ?? '')) as $line) {
                    [$label, $effect] = array_map('trim', explode('|', $line . '|none'));
                    $options[] = ['label' => $label, 'effect' => $effect];
                }
                return ['options' => $options];
            case question_type::TEXT:
                return ['maxlength' => (int) ($data['maxlength'] ?? 500)];
            case question_type::SLIDER:
                return [
                    'min' => (int) $data['slidermin'], 'max' => (int) $data['slidermax'], 'step' => (int) $data['sliderstep'],
                    'minlabel' => $data['sliderminlabel'] ?? '', 'maxlabel' => $data['slidermaxlabel'] ?? '',
                    'threshold' => $data['threshold'] ?? '',
                    'below' => $data['below'] ?? 'none',
                    'atorabove' => $data['atorabove'] ?? 'none',
                ];
            case question_type::SEMDIFF:
                $pairs = [];
                foreach ($lines((string) ($data['pairs'] ?? '')) as $line) {
                    $pairs[] = array_map('trim', explode('|', $line . '|'));
                }
                return [
                    'pairs' => $pairs, 'scale' => (int) $data['scale'],
                    'threshold' => $data['threshold'] ?? '',
                    'below' => $data['below'] ?? 'none',
                    'atorabove' => $data['atorabove'] ?? 'none',
                ];
        }
        return [];
    }

    /**
     * Form data from a stored question.
     *
     * @param \stdClass $question Question with configdata.
     * @return array
     */
    public static function data_from(\stdClass $question): array {
        $c = $question->configdata;
        return [
            'qid' => $question->id, 'qtype' => $question->qtype, 'prompt' => $question->prompt,
            'triggerkey' => $question->triggerkey, 'cooldown' => (int) $question->cooldown, 'enabled' => (int) $question->enabled,
            'mcoptions' => implode("\n", array_map(static fn($o) => $o['label'] . ' | ' . $o['effect'], $c['options'] ?? [])),
            'maxlength' => $c['maxlength'] ?? 500,
            'slidermin' => $c['min'] ?? 0, 'slidermax' => $c['max'] ?? 10, 'sliderstep' => $c['step'] ?? 1,
            'sliderminlabel' => $c['minlabel'] ?? '', 'slidermaxlabel' => $c['maxlabel'] ?? '',
            'pairs' => implode("\n", array_map(static fn($p) => $p[0] . ' | ' . $p[1], $c['pairs'] ?? [])),
            'scale' => $c['scale'] ?? 7,
            'threshold' => isset($c['threshold']) && $c['threshold'] !== null ? (string) $c['threshold'] : '',
            'below' => $c['below'] ?? 'none', 'atorabove' => $c['atorabove'] ?? 'none',
        ];
    }
}
