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
 * Seed data of an OLDER release before the upgrade test (CI job "upgrade"). CI sites only.
 *
 * Runs with the old code: it is copied into the old plugin directory and uses only tables and
 * columns that the old release has. What it writes depends on that release: data as an installation
 * before 0.4.0 left it (to exercise the upgrade steps of 0.4.0), or data as 0.4.0 and later write it
 * (to show that it survives the upgrade unchanged). tools/ci/upgrade_verify.php checks the data after
 * the upgrade; the old version is handed over in the file sideline_upgrade_from.txt in the data root.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/testing/generator/lib.php');
if (file_exists($CFG->libdir . '/testing/classes/util.php')) {
    require_once($CFG->libdir . '/testing/classes/util.php');
}

$from = (int) get_config('mod_sideline', 'version');
\core\session\manager::set_user(get_admin());
set_config('enablecompletion', 1);
set_config('crosscourse', 1, 'mod_sideline');
$gen = new testing_data_generator();
$course = $gen->create_course(['shortname' => 'upgrade_home', 'enablecompletion' => 1]);
$further = $gen->create_course(['shortname' => 'upgrade_further']);
$student = $gen->create_and_enrol($course, 'student', ['username' => 'upgrade_student']);
$gen->enrol_user($student->id, $further->id, 'student');
$gen->create_module('page', ['course' => $course->id, 'name' => 'Upgrade unit', 'completion' => COMPLETION_TRACKING_MANUAL]);
$gen->create_module('page', ['course' => $further->id, 'name' => 'Further source unit']);
$instance = $gen->create_module('sideline', ['course' => $course->id, 'sharingmode' => 'closed', 'groupminsize' => 3]);
$now = time();
if ($from < 2026110300) {
    // Before 0.4.0: a source without a teacher, a small aggregate size, a finished AI call without an end time.
    $DB->insert_record('sideline_source', (object) ['sidelineid' => $instance->id, 'courseid' => $further->id,
        'usermodified' => 0]);
    $DB->set_field('sideline', 'groupminsize', 2, ['id' => $instance->id]);
    $DB->insert_record('sideline_aiusage', (object) ['sidelineid' => $instance->id, 'purpose' => 'generate', 'success' => 1,
        'tokens' => 120, 'timecreated' => $now - 60]);
} else {
    // 0.4.0 and later: a source confirmed by a teacher with rights there, chosen event types, a finished AI call.
    $DB->insert_record('sideline_source', (object) ['sidelineid' => $instance->id, 'courseid' => $further->id,
        'usermodified' => get_admin()->id]);
    $DB->set_field('sideline', 'groupevents', 'viewed,passed', ['id' => $instance->id]);
    $DB->insert_record('sideline_aiusage', (object) ['sidelineid' => $instance->id, 'purpose' => 'generate', 'success' => 1,
        'tokens' => 120, 'timecreated' => $now - 60, 'timecompleted' => $now - 55]);
}
$DB->insert_record('sideline_cache', (object) ['sidelineid' => $instance->id, 'userid' => $student->id, 'fingerprint' => 'old',
    'source' => 'template', 'commentary' => '{"blocks":[]}', 'dirty' => 0, 'nexttransition' => 0, 'stamp' => 0,
    'timecreated' => $now, 'timemodified' => $now]);
file_put_contents($CFG->dataroot . '/sideline_upgrade_from.txt', (string) $from);
echo "Seeded instance {$instance->id} of version $from\n";
