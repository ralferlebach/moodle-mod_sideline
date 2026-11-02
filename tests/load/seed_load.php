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
 * Seed a course for the HTTP load test (P-39). Disposable development and CI sites only.
 *
 * Creates a course with activities that learners can mark complete themselves, one Sideline
 * instance shown on its own page, and learners with a known password. Half of the learners get
 * some progress, so the commentary differs between them. Prints the values the k6 plan needs as
 * shell "export" lines.
 *
 * With --groups=N the coach runs in closed group mode: the learners are spread over N groups, all of
 * them share and every second one has a completion of yesterday, so that every request goes through
 * the group path (issue #7).
 *
 * Usage: php tests/load/seed_load.php [--users=50] [--activities=20] [--groups=0]
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

// Moodle 5.1 and later have a config.php in public/ that loads the real one, so this works in both layouts.
require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->libdir . '/testing/generator/lib.php');
// Moodle 4.5 keeps testing_util in this file; 5.x autoloads it (\core\test\testing_util and an alias).
if (file_exists($CFG->libdir . '/testing/classes/util.php')) {
    require_once($CFG->libdir . '/testing/classes/util.php');
}

[$options] = cli_get_params(['users' => 50, 'activities' => 20, 'groups' => 0, 'help' => false]);
if ($options['help']) {
    echo "php tests/load/seed_load.php [--users=50] [--activities=20] [--groups=0]\n";
    exit(0);
}
// A safety net against running this on a production site by mistake: localhost only, unless explicitly allowed.
if (empty($CFG->sideline_allow_load_seed) && !preg_match('#^https?://(127\.0\.0\.1|localhost)(:\d+)?(/|$)#', $CFG->wwwroot)) {
    cli_error('Refusing to seed a site that is not on localhost. Set $CFG->sideline_allow_load_seed = true to override.');
}

\core\session\manager::set_user(get_admin());
// No mail from enrolments or account creation; such notices would also end up in the printed exports.
$CFG->noemailever = true;
ob_start();
set_config('enablecompletion', 1);
$generator = testing_util::get_data_generator();
$password = 'Load!pass1';
$stamp = time();

$course = $generator->create_course(['fullname' => 'Sideline load ' . $stamp, 'shortname' => 'slload' . $stamp,
    'enablecompletion' => 1]);
$pages = [];
for ($i = 1; $i <= (int) $options['activities']; $i++) {
    $pages[] = $generator->create_module('page', ['course' => $course->id, 'name' => 'Unit ' . $i,
        'completion' => COMPLETION_TRACKING_MANUAL]);
}
$groupmode = (int) $options['groups'] > 0;
$sideline = $generator->create_module('sideline', ['course' => $course->id, 'display' => 'page', 'name' => 'Coach']
    + ($groupmode ? ['sharingmode' => 'closed', 'grouppublish' => 'general', 'groupminsize' => 3] : []));
$groups = [];
for ($g = 0; $g < (int) $options['groups']; $g++) {
    $groups[] = $generator->create_group(['courseid' => $course->id, 'name' => 'Load group ' . $g]);
}
$instance = $DB->get_record('sideline', ['id' => $sideline->id]);

$completion = new completion_info($course);
$prefix = 'slload' . $stamp . '_';
for ($i = 0; $i < (int) $options['users']; $i++) {
    $user = $generator->create_user(['username' => $prefix . $i, 'password' => $password, 'firstname' => 'Load' . $i,
        'lastname' => 'User', 'policyagreed' => 1]);
    $generator->enrol_user($user->id, $course->id, 'student');
    if ($groupmode) {
        $generator->create_group_member(['groupid' => $groups[$i % count($groups)]->id, 'userid' => $user->id]);
        $DB->insert_record('sideline_publish', (object) ['sidelineid' => $instance->id, 'userid' => $user->id, 'publish' => 1,
            'named' => 0, 'infoversion' => 999, 'timecreated' => $stamp, 'timemodified' => $stamp]);
    }
    if ($i % 2) {
        $cm = get_coursemodule_from_instance('page', $pages[$i % count($pages)]->id);
        $completion->update_state($cm, COMPLETION_COMPLETE, $user->id);
        // Yesterday: inside the window of anonymous group statements.
        $DB->set_field('course_modules_completion', 'timemodified', $stamp - DAYSECS, ['coursemoduleid' => $cm->id,
            'userid' => $user->id]);
    }
}

ob_end_clean();
$cmids = array_map(static fn($p) => (int) $p->cmid, $pages);
echo "export BASE_URL='" . $CFG->wwwroot . "'\n";
echo "export COURSEID='" . $course->id . "'\n";
echo "export CMID='" . $sideline->cmid . "'\n";
echo "export USER_PREFIX='" . $prefix . "'\n";
echo "export USERS='" . (int) $options['users'] . "'\n";
echo "export PASSWORD='" . $password . "'\n";
echo "export PAGE_CMIDS='" . implode(',', $cmids) . "'\n";
