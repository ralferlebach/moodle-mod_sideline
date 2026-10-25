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
 * Lists all Sideline activities of a course.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);
require_course_login($course);

$PAGE->set_url('/mod/sideline/index.php', ['id' => $id]);
$PAGE->set_title(get_string('modulenameplural', 'mod_sideline'));
$PAGE->set_heading(format_string($course->fullname));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'mod_sideline'));

$modinfo = get_fast_modinfo($course);
$instances = $modinfo->get_instances_of('sideline');
if (!$instances) {
    notice(
        get_string('thereareno', 'moodle', get_string('modulenameplural', 'mod_sideline')),
        new moodle_url('/course/view.php', ['id' => $course->id])
    );
}
$table = new html_table();
$table->head = [get_string('name')];
foreach ($instances as $cm) {
    if ($cm->uservisible) {
        $table->data[] = [html_writer::link($cm->url, format_string($cm->name))];
    }
}
echo html_writer::table($table);
echo $OUTPUT->footer();
