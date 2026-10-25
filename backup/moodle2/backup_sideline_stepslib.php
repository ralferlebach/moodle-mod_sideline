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
 * Backup structure of mod_sideline.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Defines the backup structure: the instance, its explicit activity scope, admitted groups, custom
 * rules and interview questions. Configuration only: no answers, consents, sharing decisions,
 * persona choices, caches or traces are ever part of a backup. The course union is not backed up,
 * because source courses and the rights checked for them belong to the original site.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_sideline_activity_structure_step extends backup_activity_structure_step {
    #[\Override]
    protected function define_structure() {
        $sideline = new backup_nested_element('sideline', ['id'], [
            'name', 'intro', 'introformat', 'scopetype', 'scopesection', 'persona', 'aienabled', 'typing',
            'consentrequired', 'consentversion', 'configversion', 'timecreated', 'timemodified',
            'display', 'scopesubsections', 'personas', 'personachoice', 'dialogenabled', 'dialogcooldown',
            'sharingmode', 'grouppublish', 'groupminsize', 'groupversion',
        ]);
        $scopes = new backup_nested_element('scopes');
        $scope = new backup_nested_element('scope', ['id'], ['cmid']);
        $groups = new backup_nested_element('groups');
        $group = new backup_nested_element('group', ['id'], ['groupid', 'groupingid']);
        $rules = new backup_nested_element('rules');
        $rule = new backup_nested_element('rule', ['id'], ['rulekey', 'definition', 'enabled', 'sortorder', 'timecreated',
            'timemodified']);
        $questions = new backup_nested_element('questions');
        $question = new backup_nested_element('question', ['id'], ['qtype', 'prompt', 'config', 'triggerkey', 'cooldown',
            'sortorder', 'enabled', 'timecreated', 'timemodified']);

        $sideline->add_child($scopes);
        $scopes->add_child($scope);
        $sideline->add_child($groups);
        $groups->add_child($group);
        $sideline->add_child($rules);
        $rules->add_child($rule);
        $sideline->add_child($questions);
        $questions->add_child($question);

        $sideline->set_source_table('sideline', ['id' => backup::VAR_ACTIVITYID]);
        $scope->set_source_table('sideline_scope', ['sidelineid' => backup::VAR_PARENTID]);
        $group->set_source_table('sideline_group', ['sidelineid' => backup::VAR_PARENTID]);
        $rule->set_source_table('sideline_rule', ['sidelineid' => backup::VAR_PARENTID]);
        $question->set_source_table('sideline_question', ['sidelineid' => backup::VAR_PARENTID]);

        $group->annotate_ids('group', 'groupid');
        $group->annotate_ids('grouping', 'groupingid');

        $sideline->annotate_files('mod_sideline', 'intro', null);

        return $this->prepare_activity_structure($sideline);
    }
}
