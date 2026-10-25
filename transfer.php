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
 * Teacher page: export and selective import of the configuration (P1-09). No personal data.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use mod_sideline\form\transfer_form;
use mod_sideline\local\transfer\config_transfer;

$id = required_param('id', PARAM_INT);
$export = optional_param('export', 0, PARAM_BOOL);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'sideline');
$sideline = $DB->get_record('sideline', ['id' => $cm->instance], '*', MUST_EXIST);
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/sideline:manage', $context);

$url = new moodle_url('/mod/sideline/transfer.php', ['id' => $cm->id]);
$PAGE->set_url($url);
$PAGE->set_title(get_string('transfer', 'mod_sideline'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->activityheader->disable();

if ($export && confirm_sesskey()) {
    $json = json_encode(config_transfer::export($sideline), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    send_file($json, 'sideline-config-' . $cm->id . '.json', 0, 0, true, true, 'application/json');
}

$form = new transfer_form();
$form->set_data(['id' => $cm->id]);
$report = null;
if ($form->is_cancelled()) {
    redirect(new moodle_url('/mod/sideline/view.php', ['id' => $cm->id]));
}
if ($data = $form->get_data()) {
    $content = $form->get_file_content('importfile');
    $parts = array_values(array_filter(config_transfer::PARTS, static fn($p) => !empty($data->{'part_' . $p})));
    try {
        $migrated = config_transfer::migrate(json_decode((string) $content, true));
        $report = config_transfer::preview($sideline, $migrated, $parts, $data->conflict);
        if (!empty($data->confirm)) {
            config_transfer::apply($sideline, $report['plan']);
            redirect($url, get_string('transfer_done', 'mod_sideline'), null, \core\output\notification::NOTIFY_SUCCESS);
        }
    } catch (moodle_exception $e) {
        $report = ['changes' => [], 'warnings' => [$e->getMessage()]];
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('transfer', 'mod_sideline'));
echo $OUTPUT->notification(get_string('transfer_privacy', 'mod_sideline'), 'info');
echo $OUTPUT->single_button(new moodle_url($url, ['export' => 1, 'sesskey' => sesskey()]), get_string('export', 'mod_sideline'));
if ($report !== null) {
    echo $OUTPUT->box_start('generalbox');
    echo html_writer::tag('h3', get_string('transfer_preview', 'mod_sideline'));
    echo html_writer::alist(array_map('s', $report['changes'] ?: [get_string('transfer_nochanges', 'mod_sideline')]));
    foreach ($report['warnings'] as $warning) {
        echo $OUTPUT->notification(s($warning), 'warning');
    }
    echo $OUTPUT->box_end();
}
$form->display();
echo $OUTPUT->footer();
