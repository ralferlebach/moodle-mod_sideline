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
 * Restore structure of mod_sideline.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Restores the instance and its scope rows. Scope references are remapped in after_restore().
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_sideline_activity_structure_step extends restore_activity_structure_step {
    #[\Override]
    protected function define_structure() {
        return $this->prepare_activity_structure([
            new restore_path_element('sideline', '/activity/sideline'),
            new restore_path_element('sideline_scope', '/activity/sideline/scopes/scope'),
            new restore_path_element('sideline_group', '/activity/sideline/groups/group'),
            new restore_path_element('sideline_rule', '/activity/sideline/rules/rule'),
            new restore_path_element('sideline_question', '/activity/sideline/questions/question'),
        ]);
    }

    /**
     * Restore the instance.
     *
     * @param array $data Backup data.
     */
    protected function process_sideline($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->course = $this->get_courseid();
        // Whatever was generated elsewhere is stale here: start the configuration version afresh.
        $data->configversion = (int) $data->configversion + 1;
        $newid = $DB->insert_record('sideline', $data);
        $this->apply_activity_instance($newid);
        $this->set_mapping('sideline', $oldid, $newid);
    }

    /**
     * Restore one scope row with the old course module id; the task maps it afterwards.
     *
     * @param array $data Backup data.
     */
    protected function process_sideline_scope($data) {
        global $DB;

        $data = (object) $data;
        $data->sidelineid = $this->get_new_parentid('sideline');
        $DB->insert_record('sideline_scope', $data);
    }

    /**
     * Restore an admitted group or grouping, mapped to the restored one; dropped when groups were not restored.
     *
     * @param array $data Backup data.
     */
    protected function process_sideline_group($data) {
        global $DB;

        $data = (object) $data;
        $data->sidelineid = $this->get_new_parentid('sideline');
        $data->groupid = $data->groupid ? (int) $this->get_mappingid('group', $data->groupid) : 0;
        $data->groupingid = $data->groupingid ? (int) $this->get_mappingid('grouping', $data->groupingid) : 0;
        if ($data->groupid || $data->groupingid) {
            $DB->insert_record('sideline_group', $data);
        }
    }

    /**
     * Restore a custom rule; activity references are remapped in after_restore().
     *
     * @param array $data Backup data.
     */
    protected function process_sideline_rule($data) {
        global $DB;

        $data = (object) $data;
        $data->sidelineid = $this->get_new_parentid('sideline');
        $DB->insert_record('sideline_rule', $data);
    }

    /**
     * Restore an interview question (never answers).
     *
     * @param array $data Backup data.
     */
    protected function process_sideline_question($data) {
        global $DB;

        $data = (object) $data;
        $data->sidelineid = $this->get_new_parentid('sideline');
        $DB->insert_record('sideline_question', $data);
    }

    #[\Override]
    protected function after_execute() {
        $this->add_related_files('mod_sideline', 'intro', null);
    }

    #[\Override]
    protected function after_restore() {
        global $DB;

        // Remap the explicit activity scope to the restored course modules. Scope rows whose target
        // has no mapping (for example an activity that was not part of the backup) are dropped rather
        // than kept as foreign ids. Restoring into a course with the Sideline activity placed before
        // its targets is covered by tests/backup/backup_restore_test.php.
        $newid = $this->get_task()->get_activityid();
        // Custom rules: activity references follow the restored course modules; a rule whose reference
        // cannot be mapped is disabled rather than pointed at a wrong activity.
        foreach ($DB->get_records('sideline_rule', ['sidelineid' => $newid]) as $row) {
            $definition = json_decode($row->definition, true);
            if (!is_array($definition)) {
                continue;
            }
            $enabled = (int) $row->enabled;
            foreach ($definition['conditions'] ?? [] as $i => $condition) {
                if (!empty($condition['cmid'])) {
                    $mapped = restore_dbops::get_backup_ids_record($this->get_restoreid(), 'course_module', $condition['cmid']);
                    if ($mapped && !empty($mapped->newitemid)) {
                        $definition['conditions'][$i]['cmid'] = (int) $mapped->newitemid;
                    } else {
                        $enabled = 0;
                    }
                }
            }
            $DB->update_record('sideline_rule', (object) ['id' => $row->id, 'definition' => json_encode($definition),
                'enabled' => $enabled]);
        }
        foreach ($DB->get_records('sideline_scope', ['sidelineid' => $newid]) as $row) {
            $mapped = restore_dbops::get_backup_ids_record($this->get_restoreid(), 'course_module', $row->cmid);
            if ($mapped && !empty($mapped->newitemid)) {
                $DB->set_field('sideline_scope', 'cmid', $mapped->newitemid, ['id' => $row->id]);
            } else {
                $DB->delete_records('sideline_scope', ['id' => $row->id]);
            }
        }
    }
}
