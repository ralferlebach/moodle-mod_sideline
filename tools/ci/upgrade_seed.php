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
 * (to show that it survives the upgrade unchanged). Every release gets a group with sharing members and
 * their events of yesterday, and an AI call of the learner as that release registered it, next to an AI
 * call of another plugin. 0.4.1 also gets an entry in its application cache "groupsnapshot".
 * tools/ci/upgrade_verify.php checks the data after the upgrade; the old version (and whether it was
 * 0.4.1, which has the same version number as 0.4.0) is handed over in the file sideline_upgrade_from.txt
 * in the data root.
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
// Group mode: the learner and four members of one group, all sharing; three of them completed and viewed the
// unit yesterday, so that an anonymous statement is due right after the upgrade.
$group = $gen->create_group(['courseid' => $course->id, 'name' => 'Upgrade group']);
$gen->create_group_member(['groupid' => $group->id, 'userid' => $student->id]);
[, $unit] = get_course_and_cm_from_instance(
    $DB->get_field('page', 'id', ['name' => 'Upgrade unit'], MUST_EXIST),
    'page'
);
$yesterday = usergetmidnight($now, core_date::get_server_timezone()) - 12 * HOURSECS;
$groupversion = (int) $DB->get_field('sideline', 'groupversion', ['id' => $instance->id]);
foreach (
    [$student, ...array_map(
        static fn(int $i) => $gen->create_and_enrol($course, 'student', ['username' => 'upgrade_member' . $i]),
        [1, 2, 3, 4]
    )] as $i => $member
) {
    if ($i > 0) {
        $gen->create_group_member(['groupid' => $group->id, 'userid' => $member->id]);
    }
    $DB->insert_record('sideline_publish', (object) ['sidelineid' => $instance->id, 'userid' => $member->id, 'publish' => 1,
        'named' => 0, 'infoversion' => $groupversion, 'timecreated' => $now, 'timemodified' => $now]);
    if ($i >= 1 && $i <= 3) {
        $DB->insert_record('course_modules_completion', (object) ['coursemoduleid' => $unit->id, 'userid' => $member->id,
            'completionstate' => COMPLETION_COMPLETE, 'viewed' => 1, 'timemodified' => $yesterday]);
        $DB->insert_record('course_modules_viewed', (object) ['coursemoduleid' => $unit->id, 'userid' => $member->id,
            'timecreated' => $yesterday]);
    }
}

// 0.4.1 has the same version number as 0.4.0; it is the release with the cache definition.
$is041 = file_exists(__DIR__ . '/db/caches.php');
$snapshotfiles = 0;
if ($is041) {
    \cache::make('mod_sideline', 'groupsnapshot')->set('upgradeseed', [(int) $student->id]);
    // The entry is on disk now: the check after the upgrade must find it gone, not never written.
    // The file store keeps a definition in a directory named after its id, and every entry in a .cache file.
    $dirname = 'mod_sideline_groupsnapshot';
    foreach ([$CFG->cachedir, $CFG->dataroot . '/cache', $CFG->localcachedir] as $dir) {
        foreach (glob($dir . '/cachestore_file/*/' . $dirname, GLOB_ONLYDIR) ?: [] as $store) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($store, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                $snapshotfiles += str_ends_with((string) $file, '.cache') ? 1 : 0;
            }
        }
    }
}

// An AI call of the learner as the old release registered it (0.4.2 and later in the user context with a record of
// origin, 0.4.1 in the user context without, earlier releases in the activity's context), and one of another plugin
// in the same user context.
$aicall = static function (int $contextid, string $prompt) use ($DB, $student, $now): int {
    $text = ['prompt' => $prompt, 'responseid' => 'resp', 'fingerprint' => 'fp', 'generatedcontent' => 'Answer to ' . $prompt];
    $register = ['actionname' => 'generate_text', 'success' => 1, 'userid' => $student->id, 'contextid' => $contextid,
        'provider' => 'aiprovider_openai', 'timecreated' => $now - 30, 'timecompleted' => $now - 29];
    if (array_key_exists('courseid', $DB->get_columns('ai_action_register'))) {
        $register['courseid'] = 0;
    }
    $register['actionid'] = $DB->insert_record('ai_action_generate_text', (object) $text);
    return $DB->insert_record('ai_action_register', (object) $register);
};
[, $sidelinecm] = get_course_and_cm_from_instance($instance->id, 'sideline');
$usercontext = context_user::instance($student->id)->id;
// The form of the plugin's prompts (prompt_builder) since 0.3.0.
$sidelineprompt = "Sideline prompt of the old release.\n\nFACTS:\n{}\n\nOUTPUT FORMAT: respond with JSON only, no other text:\n{}";
if ($DB->get_manager()->table_exists('sideline_aicall')) {
    // 0.4.2 and later register the call in the user context and record its origin right away, as the plugin does.
    $DB->insert_record('sideline_aicall', (object) ['sidelineid' => $instance->id, 'userid' => $student->id,
        'actionid' => $aicall($usercontext, $sidelineprompt), 'timecreated' => $now - 29]);
} else {
    $aicall($is041 ? $usercontext : context_module::instance($sidelinecm->id)->id, $sidelineprompt);
}
$aicall($usercontext, 'Prompt of another plugin');
// Another placement in the activity's own context, for example the text editor while a teacher edits the activity.
$aicall(context_module::instance($sidelinecm->id)->id, 'Prompt of the editor in the activity');

$DB->insert_record('sideline_cache', (object) ['sidelineid' => $instance->id, 'userid' => $student->id, 'fingerprint' => 'old',
    'source' => 'template', 'commentary' => '{"blocks":[]}', 'dirty' => 0, 'nexttransition' => 0, 'stamp' => 0,
    'timecreated' => $now, 'timemodified' => $now]);
file_put_contents($CFG->dataroot . '/sideline_upgrade_from.txt', $from . ($is041 ? ' 0.4.1' : ''));
file_put_contents($CFG->dataroot . '/sideline_upgrade_snapshots.txt', (string) $snapshotfiles);
echo "Seeded instance {$instance->id} of version $from" . ($is041 ? ' (0.4.1)' : '') . "\n";
