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
 * View page of a Sideline activity.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT);
[$course, $cm] = get_course_and_cm_from_cmid($id, 'sideline');
$sideline = $DB->get_record('sideline', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/sideline:view', $context);

sideline_view($sideline, $course, $cm, $context);

$PAGE->set_url('/mod/sideline/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($sideline->name));
$PAGE->set_heading(format_string($course->fullname));

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($sideline->name));
if (trim(strip_tags($sideline->intro))) {
    echo $OUTPUT->box(format_module_intro('sideline', $sideline, $cm->id), 'generalbox mod_introbox');
}
echo \mod_sideline\output\panel::render((int) $cm->id, (bool) $sideline->typing, false);
echo $OUTPUT->footer();
