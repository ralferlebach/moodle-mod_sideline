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
$from = is_readable($marker) ? (int) file_get_contents($marker) : 0;
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
\core\session\manager::set_user($student);
[, $cm] = get_course_and_cm_from_instance($sideline->id, 'sideline');
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

echo $failures ? "$failures check(s) failed.\n" : "Upgrade verified (from $from).\n";
exit($failures ? 1 : 0);
