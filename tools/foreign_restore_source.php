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
 * Restore on a foreign site, step 1 (P-34): build a configured course on the SOURCE site. Development sites only.
 *
 * Then back it up with admin/cli/backup.php --courseid=<printed id>, restore the file on another site
 * with admin/cli/restore_backup.php and run tools/foreign_restore_check.php there.
 *
 * Usage: php tools/foreign_restore_source.php <moodle-root>
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require($argv[1] . '/config.php');
require_once($CFG->libdir . '/testing/generator/lib.php');
// Moodle 4.5 keeps testing_util in this file; 5.x autoloads it (\core\test\testing_util and an alias).
if (file_exists($CFG->libdir . '/testing/classes/util.php')) {
    require_once($CFG->libdir . '/testing/classes/util.php');
}
\core\session\manager::set_user(get_admin());
set_config('enablecompletion', 1);
$gen = testing_util::get_data_generator();
$course = $gen->create_course(['shortname' => 'foreign' . time(), 'fullname' => 'Foreign source', 'enablecompletion' => 1]);
$quiz = $gen->create_module('page', ['course' => $course->id, 'name' => 'Lab report', 'completion' => 1]);
$other = $gen->create_module('page', ['course' => $course->id, 'name' => 'Reading', 'completion' => 1]);
$student = $gen->create_and_enrol($course, 'student');
$sl = $gen->create_module('sideline', ['course' => $course->id, 'name' => 'Coach', 'scopetype' => 'activities',
    'scopecmids' => [$quiz->cmid], 'dialogenabled' => 1, 'persona' => 'reporter', 'consentrequired' => 1]);
$p = $gen->get_plugin_generator('mod_sideline');
$p->create_question(['sidelineid' => $sl->id, 'prompt' => 'How does it go?']);
$p->create_rule($DB->get_record('sideline', ['id' => $sl->id]), ['key' => 'custom_lab', 'category' => 'next', 'priority' => 5,
    'match' => 'all', 'action' => 'open', 'conditions' => [['type' => 'activity', 'cmid' => (int) $quiz->cmid,
    'field' => 'completion', 'is' => 'no']], 'templates' => ['en' => 'Finish {label}.', 'de' => 'Schließe {label} ab.']]);
// Personal data that must NOT travel.
$sideline = $DB->get_record('sideline', ['id' => $sl->id]);
\mod_sideline\local\consent\consent_manager::record_decision($sideline, (int) $student->id, true,
    context_module::instance($sl->cmid)->id, time());
echo $course->id, ' ', $quiz->cmid, ' ', $course->shortname, "\n";
