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
 * The instance settings form of mod_sideline.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

use mod_sideline\local\ai\persona_registry;
use mod_sideline\local\instance_config;

/**
 * Settings form for a Sideline instance.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_sideline_mod_form extends moodleform_mod {
    #[\Override]
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('general', 'form'));
        $mform->addElement('text', 'name', get_string('name'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $this->standard_intro_elements();

        $mform->addElement('select', 'display', get_string('display', 'mod_sideline'), [
            instance_config::DISPLAY_INLINE => get_string('display_inline', 'mod_sideline'),
            instance_config::DISPLAY_PAGE => get_string('display_page', 'mod_sideline'),
        ]);
        $mform->setDefault('display', instance_config::DISPLAY_INLINE);
        $mform->addHelpButton('display', 'display', 'mod_sideline');

        // Scope.
        $mform->addElement('header', 'scopehdr', get_string('scopeheader', 'mod_sideline'));
        $mform->addElement('select', 'scopetype', get_string('scopetype', 'mod_sideline'), [
            instance_config::SCOPE_COURSE => get_string('scope_course', 'mod_sideline'),
            instance_config::SCOPE_SECTION => get_string('scope_section', 'mod_sideline'),
            instance_config::SCOPE_ACTIVITIES => get_string('scope_activities', 'mod_sideline'),
        ]);
        $mform->setDefault('scopetype', instance_config::SCOPE_COURSE);

        $mform->addElement('select', 'scopesection', get_string('scopesection', 'mod_sideline'), $this->section_options());
        $mform->setType('scopesection', PARAM_INT);
        $mform->hideIf('scopesection', 'scopetype', 'neq', instance_config::SCOPE_SECTION);
        $mform->addHelpButton('scopesection', 'scopesection', 'mod_sideline');

        $mform->addElement('advcheckbox', 'scopesubsections', get_string('scopesubsections', 'mod_sideline'));
        $mform->setDefault('scopesubsections', 1);
        $mform->hideIf('scopesubsections', 'scopetype', 'neq', instance_config::SCOPE_SECTION);

        $mform->addElement(
            'autocomplete',
            'scopecmids',
            get_string('scopeactivities', 'mod_sideline'),
            $this->activity_options(),
            ['multiple' => true]
        );
        $mform->setType('scopecmids', PARAM_INT);
        $mform->hideIf('scopecmids', 'scopetype', 'neq', instance_config::SCOPE_ACTIVITIES);

        if (get_config('mod_sideline', 'crosscourse')) {
            $mform->addElement('course', 'sourcecourses', get_string('sourcecourses', 'mod_sideline'), [
                'multiple' => true,
                'exclude' => [(int) ($this->current->course ?? 0)],
                'requiredcapabilities' => ['moodle/course:manageactivities'],
            ]);
            $mform->addHelpButton('sourcecourses', 'sourcecourses', 'mod_sideline');
            // Course branches: only categories in which the teacher may manage activities (L-19, P-43).
            $categories = \core_course_category::make_categories_list('moodle/course:manageactivities');
            if ($categories) {
                $mform->addElement(
                    'autocomplete',
                    'sourcecategories',
                    get_string('sourcecategories', 'mod_sideline'),
                    $categories,
                    ['multiple' => true]
                );
                $mform->setType('sourcecategories', PARAM_INT);
                $mform->addHelpButton('sourcecategories', 'sourcecategories', 'mod_sideline');
            }
        }

        // Persona.
        $mform->addElement('header', 'personahdr', get_string('personaheader', 'mod_sideline'));
        $options = persona_registry::options();
        $mform->addElement('select', 'persona', get_string('persona', 'mod_sideline'), $options);
        $mform->setDefault('persona', persona_registry::DEFAULT_KEY);
        $mform->addHelpButton('persona', 'persona', 'mod_sideline');
        $mform->addElement('autocomplete', 'personas', get_string('personas', 'mod_sideline'), $options, ['multiple' => true]);
        $mform->addHelpButton('personas', 'personas', 'mod_sideline');
        $mform->addElement('advcheckbox', 'personachoice', get_string('personachoice', 'mod_sideline'));
        $mform->addHelpButton('personachoice', 'personachoice', 'mod_sideline');

        $mform->addElement('advcheckbox', 'typing', get_string('typing', 'mod_sideline'));
        $mform->setDefault('typing', 1);
        $mform->addHelpButton('typing', 'typing', 'mod_sideline');

        // Interview.
        $mform->addElement('header', 'dialoghdr', get_string('dialogheader', 'mod_sideline'));
        $mform->addElement('advcheckbox', 'dialogenabled', get_string('dialogenabled', 'mod_sideline'));
        $mform->addHelpButton('dialogenabled', 'dialogenabled', 'mod_sideline');
        $mform->addElement(
            'duration',
            'dialogcooldown',
            get_string('dialogcooldown', 'mod_sideline'),
            ['units' => [HOURSECS, DAYSECS, WEEKSECS]],
        );
        $mform->setDefault('dialogcooldown', DAYSECS);
        $mform->hideIf('dialogcooldown', 'dialogenabled', 'notchecked');

        // Group mode.
        $mform->addElement('header', 'grouphdr', get_string('groupheader', 'mod_sideline'));
        $mform->addElement('select', 'sharingmode', get_string('sharingmode', 'mod_sideline'), [
            instance_config::GROUP_INDIVIDUAL => get_string('sharingmode_individual', 'mod_sideline'),
            instance_config::GROUP_CLOSED => get_string('sharingmode_closed', 'mod_sideline'),
            instance_config::GROUP_OPEN => get_string('sharingmode_open', 'mod_sideline'),
        ]);
        $mform->setDefault('sharingmode', instance_config::GROUP_INDIVIDUAL);
        $mform->addHelpButton('sharingmode', 'sharingmode', 'mod_sideline');
        $levels = [];
        foreach (instance_config::PUBLISH_LEVELS as $level) {
            $levels[$level] = get_string('publish_' . $level, 'mod_sideline');
        }
        $mform->addElement('select', 'grouppublish', get_string('grouppublish', 'mod_sideline'), $levels);
        $mform->addHelpButton('grouppublish', 'grouppublish', 'mod_sideline');
        $mform->hideIf('grouppublish', 'sharingmode', 'eq', instance_config::GROUP_INDIVIDUAL);
        $mform->addElement('text', 'groupminsize', get_string('groupminsize', 'mod_sideline'), ['size' => 3]);
        $mform->setType('groupminsize', PARAM_INT);
        $mform->setDefault('groupminsize', 3);
        $mform->addHelpButton('groupminsize', 'groupminsize', 'mod_sideline');
        $mform->hideIf('groupminsize', 'sharingmode', 'eq', instance_config::GROUP_INDIVIDUAL);
        [$groups, $groupings] = $this->group_options();
        $mform->addElement('autocomplete', 'groupids', get_string('groupids', 'mod_sideline'), $groups, ['multiple' => true]);
        $mform->hideIf('groupids', 'sharingmode', 'eq', instance_config::GROUP_INDIVIDUAL);
        $mform->addElement(
            'autocomplete',
            'groupingids',
            get_string('groupingids', 'mod_sideline'),
            $groupings,
            ['multiple' => true],
        );
        $mform->hideIf('groupingids', 'sharingmode', 'eq', instance_config::GROUP_INDIVIDUAL);

        // AI and consent.
        $mform->addElement('header', 'aihdr', get_string('aiheader', 'mod_sideline'));
        $mform->addElement('advcheckbox', 'aienabled', get_string('aienabled', 'mod_sideline'));
        $mform->setDefault('aienabled', 0);
        $mform->addHelpButton('aienabled', 'aienabled', 'mod_sideline');

        $mform->addElement('advcheckbox', 'consentrequired', get_string('consentrequired', 'mod_sideline'));
        $mform->setDefault('consentrequired', 0);
        $mform->addHelpButton('consentrequired', 'consentrequired', 'mod_sideline');

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    #[\Override]
    public function data_preprocessing(&$defaultvalues) {
        parent::data_preprocessing($defaultvalues);
        if (!empty($this->current->instance)) {
            $id = (int) $this->current->instance;
            $defaultvalues['scopecmids'] = instance_config::get_scope_activities($id);
            $defaultvalues['sourcecourses'] = instance_config::get_sources($id);
            $defaultvalues['sourcecategories'] = instance_config::get_source_categories($id);
            [$groups, $groupings] = instance_config::get_groups($id);
            $defaultvalues['groupids'] = $groups;
            $defaultvalues['groupingids'] = $groupings;
            if (isset($defaultvalues['personas']) && is_string($defaultvalues['personas'])) {
                $defaultvalues['personas'] = array_filter(explode(',', $defaultvalues['personas']));
            }
        }
    }

    #[\Override]
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (($data['scopetype'] ?? '') === instance_config::SCOPE_ACTIVITIES && empty($data['scopecmids'])) {
            $errors['scopecmids'] = get_string('err_scopeactivities', 'mod_sideline');
        }
        if (!in_array($data['persona'] ?? '', persona_registry::keys(), true)) {
            $errors['persona'] = get_string('err_persona', 'mod_sideline');
        }
        $mode = $data['sharingmode'] ?? instance_config::GROUP_INDIVIDUAL;
        $allowed = $mode === instance_config::GROUP_OPEN ? ['general', 'group_only', 'named_with_group'] : ['general', 'named'];
        if ($mode !== instance_config::GROUP_INDIVIDUAL && !in_array($data['grouppublish'] ?? '', $allowed, true)) {
            $errors['grouppublish'] = get_string('err_grouppublish', 'mod_sideline');
        }
        $min = (int) ($data['groupminsize'] ?? 3);
        if ($mode !== instance_config::GROUP_INDIVIDUAL && ($min < 2 || $min > 50)) {
            $errors['groupminsize'] = get_string('err_groupminsize', 'mod_sideline');
        }
        return $errors;
    }

    /**
     * Activities of the course that can be observed, keyed by course module id.
     *
     * @return string[]
     */
    private function activity_options(): array {
        $options = [];
        if (empty($this->current->course)) {
            return $options;
        }
        $modinfo = get_fast_modinfo((int) $this->current->course);
        foreach ($modinfo->get_cms() as $cm) {
            // Other Sideline instances and subsection containers are never observed.
            if ($cm->modname === 'sideline' || $cm->deletioninprogress || $cm->get_delegated_section_info()) {
                continue;
            }
            $options[$cm->id] = format_string($cm->name, true, ['context' => $cm->context]);
        }
        return $options;
    }

    /**
     * Sections of the course, including delegated ones.
     *
     * @return string[]
     */
    private function section_options(): array {
        $options = [0 => get_string('section') . ' 0'];
        if (empty($this->current->course)) {
            return $options;
        }
        $course = get_course((int) $this->current->course);
        foreach (get_fast_modinfo($course)->get_section_info_all() as $section) {
            $options[$section->section] = get_section_name($course, $section);
        }
        return $options;
    }

    /**
     * Groups and groupings of the course.
     *
     * @return array [groups, groupings]
     */
    private function group_options(): array {
        if (empty($this->current->course)) {
            return [[], []];
        }
        $courseid = (int) $this->current->course;
        $groups = [];
        foreach (groups_get_all_groups($courseid) as $group) {
            $groups[$group->id] = format_string($group->name);
        }
        $groupings = [];
        foreach (groups_get_all_groupings($courseid) as $grouping) {
            $groupings[$grouping->id] = format_string($grouping->name);
        }
        return [$groups, $groupings];
    }
}
