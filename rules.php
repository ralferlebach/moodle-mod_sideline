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
 * Teacher page: custom rules of an instance, with validation and a preview on sample data (P1-09).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use mod_sideline\form\rule_form;
use mod_sideline\local\rules\rule_preview;
use mod_sideline\local\rules\rule_schema;
use mod_sideline\local\rules\rule_store;

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$ruleid = optional_param('ruleid', 0, PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'sideline');
$sideline = $DB->get_record('sideline', ['id' => $cm->instance], '*', MUST_EXIST);
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/sideline:manage', $context);

$url = new moodle_url('/mod/sideline/rules.php', ['id' => $cm->id]);
$PAGE->set_url($url);
$PAGE->set_title(get_string('rules_manage', 'mod_sideline'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->activityheader->disable();

if ($action === 'delete' && $ruleid && confirm_sesskey()) {
    rule_store::delete($sideline, $ruleid);
    redirect($url);
}

if ($action === 'edit') {
    $cmids = array_map('intval', array_keys(get_fast_modinfo($course)->get_cms()));
    $form = new rule_form(null, ['cmids' => $cmids]);
    if ($form->is_cancelled()) {
        redirect($url);
    }
    $preview = null;
    if ($data = $form->get_data()) {
        $definition = json_decode($data->definition, true);
        if (!empty($data->previewbutton)) {
            [$clean] = rule_schema::validate($definition, $cmids);
            $preview = rule_preview::preview($sideline, $clean, $data->scenario);
        } else {
            rule_store::save($sideline, $definition, (int) $data->ruleid, !empty($data->enabled));
            redirect($url);
        }
    }
    $defaults = ['id' => $cm->id, 'action' => 'edit', 'ruleid' => $ruleid];
    if ($ruleid && !$form->is_submitted()) {
        foreach (rule_store::definitions((int) $sideline->id, false) as $rule) {
            if ($rule['id'] === $ruleid) {
                $defaults['enabled'] = $rule['enabled'];
                unset($rule['id'], $rule['enabled']);
                $defaults['definition'] = json_encode($rule, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }
        }
    } else if (!$form->is_submitted()) {
        $defaults['definition'] = json_encode([
            'key' => 'custom_example', 'category' => 'next', 'priority' => 100, 'match' => 'all',
            'conditions' => [['type' => 'activity', 'cmid' => 0, 'field' => 'completion', 'is' => 'no'],
                ['type' => 'unused_for', 'cmid' => 0, 'seconds' => WEEKSECS]],
            'action' => 'open', 'cooldown' => 3 * DAYSECS, 'maxperweek' => 2,
            'templates' => ['en' => 'It has been a while since you opened {label}. Shall we pick it up again?',
                'de' => 'Du warst eine Weile nicht mehr in {label}. Wollen wir dort weitermachen?'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
    $form->set_data($defaults);
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('rules_manage', 'mod_sideline'));
    if ($preview !== null) {
        echo $OUTPUT->box_start('generalbox');
        echo html_writer::tag('h3', get_string('preview'));
        foreach ($preview as $block) {
            echo html_writer::tag('p', s($block['text']) . ' ' . html_writer::tag(
                'small',
                '(' . s($block['key']) . ')',
                ['class' => 'text-muted']
            ));
        }
        echo $OUTPUT->box_end();
    }
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('rules_manage', 'mod_sideline'));
echo $OUTPUT->notification(get_string('rules_intro', 'mod_sideline'), 'info');
$table = new html_table();
$table->head = [get_string('rulekey', 'mod_sideline'), get_string('enabled', 'mod_sideline'), ''];
foreach (rule_store::definitions((int) $sideline->id, false) as $rule) {
    $edit = html_writer::link(new moodle_url($url, ['action' => 'edit', 'ruleid' => $rule['id']]), get_string('edit'));
    $delete = html_writer::link(
        new moodle_url($url, ['action' => 'delete', 'ruleid' => $rule['id'], 'sesskey' => sesskey()]),
        get_string('delete')
    );
    $table->data[] = [s($rule['key']), $rule['enabled'] ? get_string('yes') : get_string('no'), $edit . ' · ' . $delete];
}
echo html_writer::table($table);
echo $OUTPUT->single_button(new moodle_url($url, ['action' => 'edit']), get_string('rule_add', 'mod_sideline'), 'get');
echo $OUTPUT->footer();
