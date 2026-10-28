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
 * Site page: administrator personas with versions, approval and revocation (P1-07).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use mod_sideline\form\persona_form;
use mod_sideline\local\ai\persona_registry;
use mod_sideline\local\persona\persona_manager;
use mod_sideline\local\persona\persona_preview;

$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);

admin_externalpage_setup('mod_sideline_personas');
require_capability('mod/sideline:managepersonas', context_system::instance());
$url = new moodle_url('/mod/sideline/personas.php');

if (in_array($action, ['approve', 'revoke'], true) && $id && confirm_sesskey()) {
    persona_manager::set_status($id, $action === 'approve' ? persona_manager::APPROVED : persona_manager::REVOKED, (int) $USER->id);
    redirect($url);
}

if ($action === 'preview') {
    // Preview (P-14): invented sample situations only, no learner data.
    $builtin = optional_param('builtin', '', PARAM_ALPHA);
    $lang = optional_param('lang', current_language(), PARAM_LANG);
    if (!get_string_manager()->translation_exists($lang)) {
        $lang = current_language();
    }
    $now = time();
    if ($builtin !== '') {
        $preview = persona_preview::for_builtin($builtin, $lang);
        $title = get_string('persona_' . $builtin, 'mod_sideline');
    } else {
        $row = $DB->get_record('sideline_persona', ['id' => $id], '*', MUST_EXIST);
        $preview = persona_preview::for_site_persona($id);
        $title = format_string($row->name) . ' · ' . get_string('version') . ' ' . (int) $row->version . ' · '
            . get_string('personastatus_' . $row->status, 'mod_sideline');
    }
    $here = new moodle_url($url, ['action' => 'preview', 'id' => $id, 'builtin' => $builtin, 'lang' => $lang]);
    $PAGE->set_url($here);

    $sample = null;
    $generator = new \mod_sideline\local\ai\core_text_generator();
    $aiavailable = persona_preview::ai_available($generator, (int) $USER->id, $now);
    if ($aiavailable && optional_param('generate', 0, PARAM_BOOL) && confirm_sesskey()) {
        $sample = $preview->ai_sample(new \mod_sideline\local\ai\ai_gateway($generator), (int) $USER->id, $lang, $now);
    }

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('persona_preview', 'mod_sideline') . ': ' . $title);
    echo $OUTPUT->notification(get_string('persona_preview_intro', 'mod_sideline', persona_preview::SAMPLE_NAME), 'info');

    $languages = get_string_manager()->get_list_of_translations();
    echo $OUTPUT->single_select(
        new moodle_url($url, ['action' => 'preview', 'id' => $id, 'builtin' => $builtin]),
        'lang',
        $languages,
        $lang,
        null,
        null,
        ['label' => get_string('language')]
    );

    echo $OUTPUT->heading(get_string('persona_preview_templates', 'mod_sideline'), 3);
    echo html_writer::alist(array_map('s', array_values($preview->template_texts($lang, $now))));

    echo $OUTPUT->heading(get_string('persona_preview_ai', 'mod_sideline'), 3);
    if (!$aiavailable) {
        echo $OUTPUT->notification(get_string('persona_preview_noai', 'mod_sideline'), 'warning');
    } else if ($sample === null) {
        echo $OUTPUT->single_button(
            new moodle_url($here, ['generate' => 1, 'sesskey' => sesskey()]),
            get_string('persona_preview_generate', 'mod_sideline'),
            'post'
        );
    } else if ($sample['texts'] === null) {
        echo $OUTPUT->notification(get_string('persona_preview_rejected', 'mod_sideline', s($sample['status'])), 'warning');
    } else {
        echo html_writer::alist(array_map('s', array_values($sample['texts'])));
        $checked = get_string('persona_preview_checked', 'mod_sideline', s($sample['status']));
        echo html_writer::tag('p', $checked, ['class' => 'small']);
    }

    echo $OUTPUT->heading(get_string('persona_preview_prompt', 'mod_sideline'), 3);
    echo html_writer::tag('pre', s($preview->prompt($lang, $now)), ['class' => 'border p-2 small', 'tabindex' => 0,
        'aria-label' => get_string('persona_preview_prompt', 'mod_sideline')]);
    echo $OUTPUT->single_button($url, get_string('back'), 'get');
    echo $OUTPUT->footer();
    exit;
}

if ($action === 'edit') {
    $form = new persona_form();
    if ($form->is_cancelled()) {
        redirect($url);
    }
    if ($data = $form->get_data()) {
        persona_manager::save($data, (int) $USER->id);
        redirect($url, get_string('persona_saveddraft', 'mod_sideline'));
    }
    if ($id) {
        $form->set_data($DB->get_record('sideline_persona', ['id' => $id], '*', MUST_EXIST));
    } else {
        $form->set_data(['action' => 'edit']);
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('personas_manage', 'mod_sideline'));
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('personas_manage', 'mod_sideline'));
echo $OUTPUT->notification(get_string('personas_intro', 'mod_sideline'), 'info');
$table = new html_table();
$table->head = [get_string('personashortname', 'mod_sideline'), get_string('name'), get_string('version'),
    get_string('status'), ''];
foreach ($DB->get_records('sideline_persona', null, 'shortname') as $p) {
    $links = [
        html_writer::link(new moodle_url($url, ['action' => 'edit', 'id' => $p->id]), get_string('edit')),
        html_writer::link(
            new moodle_url($url, ['action' => 'preview', 'id' => $p->id]),
            get_string('persona_preview', 'mod_sideline')
        ),
    ];
    if ($p->status !== persona_manager::APPROVED) {
        $links[] = html_writer::link(
            new moodle_url($url, ['action' => 'approve', 'id' => $p->id, 'sesskey' => sesskey()]),
            get_string('persona_approve', 'mod_sideline')
        );
    } else {
        $links[] = html_writer::link(
            new moodle_url($url, ['action' => 'revoke', 'id' => $p->id, 'sesskey' => sesskey()]),
            get_string('persona_revoke', 'mod_sideline')
        );
    }
    $table->data[] = [s($p->shortname), format_string($p->name), (int) $p->version,
        get_string('personastatus_' . $p->status, 'mod_sideline'), implode(' · ', $links)];
}
echo html_writer::table($table);
echo $OUTPUT->single_button(new moodle_url($url, ['action' => 'edit']), get_string('persona_add', 'mod_sideline'), 'get');

// The built-in personas can be previewed too.
echo $OUTPUT->heading(get_string('personas_builtin', 'mod_sideline'), 3);
$builtins = [];
foreach (persona_registry::builtin_keys() as $key) {
    $builtins[] = get_string('persona_' . $key, 'mod_sideline') . ' · ' . html_writer::link(
        new moodle_url($url, ['action' => 'preview', 'builtin' => $key]),
        get_string('persona_preview', 'mod_sideline')
    );
}
echo html_writer::alist($builtins);
echo $OUTPUT->footer();
