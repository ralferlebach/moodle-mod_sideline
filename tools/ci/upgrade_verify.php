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
 * Check the data of tools/ci/upgrade_seed.php after the upgrade to the current release. CI sites only.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');

$plugin = new stdClass();
require(__DIR__ . '/../../version.php');
$failures = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$failures): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($detail !== '' ? ': ' . $detail : '') . "\n";
    $failures += $ok ? 0 : 1;
};

$marker = $CFG->dataroot . '/sideline_upgrade_from.txt';
$seeded = is_readable($marker) ? trim(file_get_contents($marker)) : '';
$from = (int) $seeded;
$is041 = str_ends_with($seeded, ' 0.4.1');
$check('seeded version known', $from > 0, (string) $from);
$check(
    'version',
    (int) get_config('mod_sideline', 'version') === (int) $plugin->version,
    get_config('mod_sideline', 'version') . ' / ' . $plugin->version
);
$course = $DB->get_record('course', ['shortname' => 'upgrade_home'], '*', MUST_EXIST);
$student = $DB->get_record('user', ['username' => 'upgrade_student'], '*', MUST_EXIST);
$sideline = $DB->get_record('sideline', ['course' => $course->id], '*', MUST_EXIST);
$usage = $DB->get_record('sideline_aiusage', ['sidelineid' => $sideline->id], '*', MUST_EXIST);
$unconfirmed = \mod_sideline\local\instance_config::count_unconfirmed_sources((int) $sideline->id);
$cms = (new \mod_sideline\local\service\scope_resolver())->resolve($sideline, (int) $student->id);
$names = array_map(static fn($cm) => $cm->name, $cms);
$check('group minimum at least three', (int) $sideline->groupminsize === 3, (string) $sideline->groupminsize);
$check('time window of group statements: a week', (int) $sideline->groupwindow === WEEKSECS, (string) $sideline->groupwindow);
if ($from < 2026110300) {
    // The upgrade steps of 0.4.0 ran on data of an older release.
    $check('event types default', $sideline->groupevents === 'completed', (string) $sideline->groupevents);
    $check('old AI calls are completed', (int) $usage->timecompleted === (int) $usage->timecreated);
    $check('legacy source kept for confirmation', $unconfirmed === 1);
    $check('legacy source not evaluated', !in_array('Further source unit', $names, true), implode(', ', $names));
    $check('cached texts of the instance dropped', !$DB->record_exists('sideline_cache', ['sidelineid' => $sideline->id]));
    $check('upgrade log records the instance', $DB->record_exists_select(
        'upgrade_log',
        "plugin = 'mod_sideline' AND " . $DB->sql_like('details', ':d'),
        ['d' => '%instance ' . $sideline->id . '%']
    ));
} else {
    // Data written by 0.4.0 or later is kept as it is.
    $check('event types kept', $sideline->groupevents === 'viewed,passed', (string) $sideline->groupevents);
    $check('AI call kept', (int) $usage->timecompleted === (int) $usage->timecreated + 5);
    $check('confirmed source still confirmed', $unconfirmed === 0, (string) $unconfirmed);
    $check('confirmed source evaluated', in_array('Further source unit', $names, true), implode(', ', $names));
}

// Cache configuration right after the upgrade, before any code of the plugin ran: the definition of 0.4.1
// is gone (the upgrade reread the definitions because the version number changed), and so is its data.
$muc = $CFG->dataroot . '/muc/config.php';
$configuration = [];
if (is_readable($muc)) {
    include($muc);
}
$check('cache configuration read', !empty($configuration['definitions']), $muc);
$check(
    'no cache definition groupsnapshot any more',
    !isset($configuration['definitions']['mod_sideline/groupsnapshot']),
    implode(', ', array_filter(
        array_keys($configuration['definitions'] ?? []),
        static fn($k) => str_starts_with($k, 'mod_sideline/')
    ))
);
// The file store keeps a definition in a directory named after its id, and every entry in a .cache file.
$dirname = 'mod_sideline_groupsnapshot';
$left = [];
foreach ([$CFG->cachedir, $CFG->dataroot . '/cache', $CFG->localcachedir] as $dir) {
    foreach (glob($dir . '/cachestore_file/*/' . $dirname, GLOB_ONLYDIR) ?: [] as $store) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($store, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (str_ends_with((string) $file, '.cache')) {
                $left[] = (string) $file;
            }
        }
    }
}
if ($is041) {
    $seededfiles = (int) @file_get_contents($CFG->dataroot . '/sideline_upgrade_snapshots.txt');
    $check('the seed of 0.4.1 had written its entry to the cache store', $seededfiles > 0, (string) $seededfiles);
}
$check('no user ids of 0.4.1 left in the cache store', !$left, implode(', ', $left));

// Group statement directly after the upgrade, with the day count in its table.
\core\session\manager::set_user($student);
[, $cm] = get_course_and_cm_from_instance($sideline->id, 'sideline');
$debugging = $CFG->debug;
$CFG->debug = DEBUG_DEVELOPER;
ob_start();
[$statements] = (new \mod_sideline\local\group\group_service())->facts(
    $sideline,
    (int) $student->id,
    get_fast_modinfo($course, $student->id)->get_cms(),
    time()
);
$output = ob_get_clean();
$CFG->debug = $debugging;
$check('group statement after the upgrade', count($statements) === 1, json_encode($statements));
$check('no debugging messages while stating it', trim($output) === '', $output);
$member = $DB->get_record('user', ['username' => 'upgrade_member1'], '*', MUST_EXIST);
$check('day count stored for the counted people', $DB->count_records('sideline_daycount', ['sidelineid' => $sideline->id]) === 3);
$modulecontextid = context_module::instance($cm->id)->id;
\mod_sideline\local\group\sharing_manager::record($sideline, (int) $member->id, false, false, $modulecontextid, time());
$check('stopped sharing removes the day count', !$DB->record_exists('sideline_daycount', ['sidelineid' => $sideline->id,
    'userid' => $member->id]));

// AI calls of the old release: attributed when the old release registered them in the activity's context and the
// prompt has the plugin's form (upgrade step 2026110302), or when it recorded the origin itself (0.4.2 and later);
// never an entry of another plugin or of the editor in the same activity context; a withdrawal empties only what
// belongs to the instance.
$sidelineprompt = $DB->get_record_select(
    'ai_action_generate_text',
    $DB->sql_like('prompt', '?'),
    ['Sideline prompt of the old release.%'],
    '*',
    MUST_EXIST
);
$editorprompt = $DB->get_record_select(
    'ai_action_generate_text',
    $DB->sql_compare_text('prompt') . ' = ?',
    ['Prompt of the editor in the activity'],
    '*',
    MUST_EXIST
);
$foreignprompt = $DB->get_record_select(
    'ai_action_generate_text',
    $DB->sql_compare_text('prompt') . ' = ?',
    ['Prompt of another plugin'],
    '*',
    MUST_EXIST
);
$attributed = $DB->get_fieldset_select('sideline_aicall', 'actionid', 'sidelineid = ?', [$sideline->id]);
$sidelineregister = (int) $DB->get_field('ai_action_register', 'id', ['actionid' => $sidelineprompt->id]);
$foreignregister = (int) $DB->get_field('ai_action_register', 'id', ['actionid' => $foreignprompt->id]);
$editorregister = (int) $DB->get_field('ai_action_register', 'id', ['actionid' => $editorprompt->id]);
$check('AI entry of another plugin never attributed', !in_array($foreignregister, array_map('intval', $attributed), true));
$check(
    'AI entry of the editor in the activity context never attributed',
    !in_array($editorregister, array_map('intval', $attributed), true)
);
$check(
    $is041 ? 'AI entry of 0.4.1 in the user context stays unattributed' : 'AI entry in the activity context attributed',
    in_array($sidelineregister, array_map('intval', $attributed), true) === !$is041
);
\mod_sideline\local\consent\consent_manager::forget_generated((int) $sideline->id, (int) $student->id);
$check(
    'withdrawal keeps the AI entry of the editor',
    $DB->get_field('ai_action_generate_text', 'generatedcontent', ['id' => $editorprompt->id])
        === 'Answer to Prompt of the editor in the activity'
);
$check(
    'withdrawal keeps the AI entry of another plugin',
    $DB->get_field('ai_action_generate_text', 'generatedcontent', ['id' => $foreignprompt->id])
        === 'Answer to Prompt of another plugin'
);
$check(
    $is041 ? 'withdrawal leaves the unattributed entry of 0.4.1 (documented)' : 'withdrawal empties the attributed AI entry',
    ($DB->get_field('ai_action_generate_text', 'generatedcontent', ['id' => $sidelineprompt->id]) === '') === !$is041
);

$result = (new \mod_sideline\local\service\commentary_service())->get(
    $sideline,
    $course,
    $student,
    context_module::instance($cm->id),
    time()
);
$check(
    'commentary after the upgrade',
    $result['status'] === 'ok' && !empty($result['commentary']['blocks']),
    $result['commentary']['source'] ?? ''
);
$check('no outdated cached text delivered', !$DB->record_exists('sideline_cache', ['sidelineid' => $sideline->id,
    'fingerprint' => 'old']));

// The operational privacy check of the target system (#14) finds nothing that retention or the upgrade left behind.
foreach (\mod_sideline\local\privacy\operational_check::run(time()) as [$name, $result, $detail]) {
    $check("privacy check $name: $result", $result !== \mod_sideline\local\privacy\operational_check::FAIL, $detail);
}

echo $failures ? "$failures check(s) failed.\n" : "Upgrade verified (from $seeded).\n";
exit($failures ? 1 : 0);
