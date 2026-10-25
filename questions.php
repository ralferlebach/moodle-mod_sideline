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
 * Teacher page: interview questions of an instance. Answers are never shown here (L-16, P1-08).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use mod_sideline\form\question_form;
use mod_sideline\local\dialog\dialog_manager;
use mod_sideline\local\rules\rule_store;

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$qid = optional_param('qid', 0, PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'sideline');
$sideline = $DB->get_record('sideline', ['id' => $cm->instance], '*', MUST_EXIST);
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/sideline:manage', $context);

$url = new moodle_url('/mod/sideline/questions.php', ['id' => $cm->id]);
$PAGE->set_url($url);
$PAGE->set_title(get_string('questions_manage', 'mod_sideline'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->activityheader->disable();

if ($action === 'delete' && $qid && confirm_sesskey()) {
    dialog_manager::delete_question((int) $sideline->id, $qid);
    redirect($url);
}

if ($action === 'edit') {
    $triggers = ['any' => get_string('qtrigger_any', 'mod_sideline')];
    foreach (
        ['deadline_soon', 'deadline_passed', 'not_passed', 'not_passed_retry', 'not_passed_final', 'locked_requirements',
        'success', 'success_next', 'welcome_back', 'progress', 'submitted_waiting', 'course_complete'] as $key
    ) {
        $triggers[$key] = get_string('rulename_' . $key, 'mod_sideline');
    }
    foreach (rule_store::definitions((int) $sideline->id, false) as $rule) {
        $triggers[$rule['key']] = $rule['key'];
    }
    $form = new question_form(null, ['triggers' => $triggers]);
    if ($form->is_cancelled()) {
        redirect($url);
    }
    if ($data = $form->get_data()) {
        dialog_manager::save_question((int) $sideline->id, (object) [
            'id' => $data->qid ?: null,
            'qtype' => $data->qtype,
            'prompt' => $data->prompt,
            'config' => question_form::config_from((array) $data),
            'triggerkey' => $data->triggerkey,
            'cooldown' => $data->cooldown,
            'enabled' => $data->enabled,
        ]);
        redirect($url);
    }
    $defaults = ['id' => $cm->id, 'action' => 'edit'];
    if ($qid) {
        $questions = dialog_manager::questions((int) $sideline->id, false);
        if (!isset($questions[$qid])) {
            throw new moodle_exception('invalidrecord', 'error');
        }
        $defaults += question_form::data_from($questions[$qid]);
    }
    $form->set_data($defaults);
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('questions_manage', 'mod_sideline'));
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('questions_manage', 'mod_sideline'));
echo $OUTPUT->notification(get_string('questions_privacy', 'mod_sideline'), 'info');
if (empty($sideline->dialogenabled)) {
    echo $OUTPUT->notification(get_string('questions_disabled', 'mod_sideline'), 'warning');
}
$table = new html_table();
$table->head = [get_string('qprompt', 'mod_sideline'), get_string('qtype', 'mod_sideline'),
    get_string('qtrigger', 'mod_sideline'), get_string('enabled', 'mod_sideline'), ''];
foreach (dialog_manager::questions((int) $sideline->id, false) as $q) {
    $edit = html_writer::link(new moodle_url($url, ['action' => 'edit', 'qid' => $q->id]), get_string('edit'));
    $delete = html_writer::link(
        new moodle_url($url, ['action' => 'delete', 'qid' => $q->id, 'sesskey' => sesskey()]),
        get_string('delete')
    );
    $table->data[] = [format_string($q->prompt), get_string('qtype_' . $q->qtype, 'mod_sideline'), s($q->triggerkey),
        $q->enabled ? get_string('yes') : get_string('no'), $edit . ' · ' . $delete];
}
echo html_writer::table($table);
echo $OUTPUT->single_button(new moodle_url($url, ['action' => 'edit']), get_string('question_add', 'mod_sideline'), 'get');
echo $OUTPUT->footer();
