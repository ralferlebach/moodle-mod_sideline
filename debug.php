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
 * Site administrator page: debug export of one learner's commentary decision (P1-10).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use mod_sideline\form\debug_form;
use mod_sideline\local\debug\debug_exporter;

admin_externalpage_setup('mod_sideline_debug');
if (!is_siteadmin()) {
    throw new required_capability_exception(context_system::instance(), 'moodle/site:config', 'nopermissions', '');
}

$form = new debug_form();
if ($data = $form->get_data()) {
    $export = debug_exporter::export(
        (int) $data->cmid,
        (int) $data->userid,
        $data->purpose,
        (int) $data->timefrom,
        (int) $data->timeto,
        (int) $USER->id
    );
    $json = json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    send_file(
        $json,
        'sideline-debug-' . (int) $data->cmid . '-' . (int) $data->userid . '.json',
        0,
        0,
        true,
        true,
        'application/json',
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('debug_export', 'mod_sideline'));
$form->display();
echo $OUTPUT->footer();
