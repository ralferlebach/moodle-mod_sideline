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
 * Seed a course for the Playwright tests. Development and CI sites only.
 *
 * Creates a fresh course (so repeated runs never share state) with two pages that learners complete
 * themselves, an inline coach with consent gate and an interview question, a second coach on its own
 * page with typing animation, one teacher and five learners. For the accessibility checks a coach with
 * consent gate and a question of several selections on its own page, and a minimal right-to-left language
 * pack "he" (only when the site has no such pack) for the fifth learner. Prints the values the tests need
 * as shell "export" lines.
 *
 * Usage: php tests/playwright/seed.php
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

// Moodle 5.1 and later have a config.php in public/ that loads the real one, so this works in both layouts.
require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/testing/generator/lib.php');
require_once($CFG->libdir . '/completionlib.php');
// Moodle 4.5 keeps testing_util in this file; 5.x autoloads it.
if (file_exists($CFG->libdir . '/testing/classes/util.php')) {
    require_once($CFG->libdir . '/testing/classes/util.php');
}

// Localhost only, unless explicitly allowed: this creates users with a known password.
if (empty($CFG->sideline_allow_load_seed) && !preg_match('#^https?://(127\.0\.0\.1|localhost)(:\d+)?(/|$)#', $CFG->wwwroot)) {
    cli_error('Refusing to seed a site that is not on localhost. Set $CFG->sideline_allow_load_seed = true to override.');
}

\core\session\manager::set_user(get_admin());
// No mail from enrolments or account creation; such notices would also end up in the printed exports.
$CFG->noemailever = true;
ob_start();
set_config('enablecompletion', 1);
// User tours open a modal on the course page for new users and hide the page from assistive technology,
// as Behat does, they are switched off on the test site.
$DB->set_field('tool_usertours_tours', 'enabled', 0);
\cache::make('tool_usertours', 'tourdata')->purge();
$gen = testing_util::get_data_generator();
$stamp = time();
$password = 'Pw!test123';

$course = $gen->create_course(['fullname' => 'Sideline browser ' . $stamp, 'shortname' => 'slpw' . $stamp,
    'enablecompletion' => 1]);
foreach (['Unit one', 'Unit two'] as $name) {
    $gen->create_module('page', ['course' => $course->id, 'name' => $name, 'completion' => COMPLETION_TRACKING_MANUAL]);
}
$coach = $gen->create_module('sideline', ['course' => $course->id, 'name' => 'Coach', 'display' => 'inline',
    'consentrequired' => 1, 'dialogenabled' => 1, 'dialogcooldown' => 0]);
$gen->get_plugin_generator('mod_sideline')->create_question([
    'sidelineid' => $coach->id,
    'prompt' => 'How is it going?',
    'config' => ['options' => [['label' => 'Fine', 'effect' => 'none'], ['label' => 'Overwhelmed', 'effect' => 'small_steps']]],
]);
$typing = $gen->create_module('sideline', ['course' => $course->id, 'name' => 'Typing coach', 'display' => 'page',
    'typing' => 1]);
// Several selections (issue #11) and the group mode with names (issues #6, #9, #12), each on its own page.
$multi = $gen->create_module('sideline', ['course' => $course->id, 'name' => 'Multi coach', 'display' => 'page',
    'typing' => 0, 'dialogenabled' => 1, 'dialogcooldown' => 0]);
$gen->get_plugin_generator('mod_sideline')->create_question([
    'sidelineid' => $multi->id,
    'prompt' => 'What holds you back?',
    'config' => ['options' => [['label' => 'Little time', 'effect' => 'focus_deadlines'],
        ['label' => 'Tired', 'effect' => 'pause_ok'], ['label' => 'Nothing', 'effect' => 'none']],
        'multiple' => 1, 'minselect' => 1, 'maxselect' => 2],
]);
$groupcoach = $gen->create_module('sideline', ['course' => $course->id, 'name' => 'Group coach', 'display' => 'page',
    'typing' => 0, 'sharingmode' => 'closed', 'grouppublish' => 'named']);

// Accessibility checks (issue #25): consent gate and checkboxes on a page of their own.
$a11y = $gen->create_module('sideline', ['course' => $course->id, 'name' => 'A11y coach', 'display' => 'page',
    'typing' => 0, 'consentrequired' => 1, 'dialogenabled' => 1, 'dialogcooldown' => 0]);
$gen->get_plugin_generator('mod_sideline')->create_question([
    'sidelineid' => $a11y->id,
    'prompt' => 'What helps you most?',
    'config' => ['options' => [['label' => 'A plan', 'effect' => 'focus_deadlines'],
        ['label' => 'A break', 'effect' => 'pause_ok'], ['label' => 'Nothing', 'effect' => 'none']],
        'multiple' => 1, 'minselect' => 1, 'maxselect' => 2],
]);
// A right-to-left language without texts of its own, as the CI site has English only. A real pack is never touched.
$rtl = $CFG->dataroot . '/lang/he';
if (!file_exists($rtl . '/langconfig.php')) {
    check_dir_exists($rtl);
    file_put_contents($rtl . '/langconfig.php', "<?php\n\$string['thislanguage'] = 'he';\n\$string['thislanguageint'] = 'Hebrew';\n"
        . "\$string['parentlanguage'] = '';\n\$string['thisdirection'] = 'rtl';\n");
    get_string_manager()->reset_caches();
}

$exports = ['SL_BASE_URL' => $CFG->wwwroot, 'SL_COURSEID' => $course->id, 'SL_TYPING_CMID' => $typing->cmid,
    'SL_MULTI_CMID' => $multi->cmid, 'SL_GROUP_CMID' => $groupcoach->cmid, 'SL_A11Y_CMID' => $a11y->cmid,
    'SL_PASSWORD' => $password];
$users = [];
$roles = ['teacher' => 'editingteacher', 'student1' => 'student', 'student2' => 'student', 'student3' => 'student',
    'student4' => 'student', 'student5' => 'student'];
foreach ($roles as $key => $role) {
    $user = $gen->create_user(['username' => 'slpw' . $stamp . $key, 'password' => $password, 'firstname' => ucfirst($key),
        'lastname' => 'Test', 'policyagreed' => 1, 'lang' => $key === 'student5' ? 'he' : 'en']);
    $gen->enrol_user($user->id, $course->id, $role);
    $exports['SL_' . strtoupper($key)] = $user->username;
    $users[$key] = $user;
}
// Student1 and Student2 form a group; Student2 shares with name and completed "Unit one".
$group = $gen->create_group(['courseid' => $course->id, 'name' => 'Red team']);
foreach (['student1', 'student2'] as $key) {
    $gen->create_group_member(['groupid' => $group->id, 'userid' => $users[$key]->id]);
}
$groupsideline = $DB->get_record('sideline', ['id' => $groupcoach->id]);
\mod_sideline\local\group\sharing_manager::record(
    $groupsideline,
    (int) $users['student2']->id,
    true,
    true,
    context_module::instance($groupcoach->cmid)->id,
    time()
);
$unitone = get_coursemodule_from_instance('page', $DB->get_field('page', 'id', ['course' => $course->id, 'name' => 'Unit one']));
(new completion_info($course))->update_state($unitone, COMPLETION_COMPLETE, $users['student2']->id, true);
ob_end_clean();
foreach ($exports as $name => $value) {
    echo "export $name='" . $value . "'\n";
}
