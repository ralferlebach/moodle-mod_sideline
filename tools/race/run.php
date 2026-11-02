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
 * Concurrency races with real parallel processes (issues #3 and #8). Development and CI sites only.
 *
 * The PHPUnit tests produce interleavings inside one process. This script starts real PHP
 * processes against the site database, which all wait for a common start time:
 *
 * 1. budget: 20 processes of 20 different instances race for the last AI call of the hour, then
 *    for the last tokens of the day. Exactly one may get it.
 * 2. withdrawal: a learner withdraws consent while a generation with a slow provider is in flight.
 *    Nothing may be delivered, and no text may be left in the cache.
 * 3. invalidation: the learner's state changes while a generation is in flight. The text may not be
 *    stored as fresh; the generation computes again.
 *
 * Usage: php tools/race/run.php   (exit code 0 when every race held, 1 otherwise)
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/testing/generator/lib.php');
if (file_exists($CFG->libdir . '/testing/classes/util.php')) {
    require_once($CFG->libdir . '/testing/classes/util.php');
}

if ($CFG->wwwroot === 'https://moodle.example.org' || !empty($CFG->sideline_race_disabled)) {
    cli_error('Development and CI sites only.');
}
\core\session\manager::set_user(get_admin());

/**
 * Start processes together and collect their JSON outcomes.
 *
 * @param array[] $jobs Options per process.
 * @return array[] Outcome per process, in the order of the jobs.
 */
function sideline_race(array $jobs): array {
    $start = microtime(true) + 2;
    $procs = [];
    foreach ($jobs as $i => $job) {
        $args = [PHP_BINARY, __DIR__ . '/worker.php', '--start=' . $start];
        foreach ($job as $name => $value) {
            $args[] = '--' . $name . '=' . $value;
        }
        $procs[$i] = proc_open($args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
    }
    $results = [];
    foreach ($procs as $i => $proc) {
        $out = stream_get_contents($pipes[$i][1]);
        $err = stream_get_contents($pipes[$i][2]);
        proc_close($proc);
        $lines = array_filter(explode("\n", trim($out)));
        $results[$i] = json_decode((string) end($lines), true) ?? ['error' => trim($out . ' ' . $err)];
    }
    return $results;
}

$failures = 0;
$check = static function (string $name, bool $ok, string $detail) use (&$failures): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ': ' . $detail . "\n";
    $failures += $ok ? 0 : 1;
};

$gen = new testing_data_generator();
set_config('enablecompletion', 1);
set_config('aienabled', 1, 'mod_sideline');
$stamp = time() . '_' . random_int(1000, 9999);
$course = $gen->create_course(['enablecompletion' => 1, 'fullname' => 'Race ' . $stamp, 'shortname' => 'race_' . $stamp]);
$student = $gen->create_and_enrol($course, 'student', ['username' => 'race_' . $stamp]);
$gen->create_module('page', ['course' => $course->id, 'name' => 'Race unit', 'completion' => COMPLETION_TRACKING_MANUAL]);
$instance = $gen->create_module('sideline', ['course' => $course->id, 'aienabled' => 1, 'consentrequired' => 1]);
$sideline = $DB->get_record('sideline', ['id' => $instance->id]);
$context = context_module::instance($instance->cmid);

// 1. Budget: the last call of the hour, then the last tokens of the day.
$DB->delete_records('sideline_aiusage');
set_config('aihourlycap', 1, 'mod_sideline');
set_config('aidailytokencap', 0, 'mod_sideline');
$jobs = [];
for ($i = 1; $i <= 20; $i++) {
    $jobs[] = ['action' => 'reserve', 'slot' => 1000 + $i];
}
$won = count(array_filter(sideline_race($jobs), static fn($r) => !empty($r['reserved'])));
$check('budget hourly', $won === 1 && $DB->count_records('sideline_aiusage') === 1, "$won of 20 processes got the last call");

$DB->delete_records('sideline_aiusage');
set_config('aihourlycap', 1000, 'mod_sideline');
// One worst case (50 + 1024 tokens) fits, two do not.
set_config('aidailytokencap', 1500, 'mod_sideline');
$won = count(array_filter(sideline_race($jobs), static fn($r) => !empty($r['reserved'])));
$check('budget tokens', $won === 1, "$won of 20 processes got the last tokens");
$DB->delete_records('sideline_aiusage');
set_config('aihourlycap', 1000, 'mod_sideline');
set_config('aidailytokencap', 0, 'mod_sideline');

// 2. Withdrawal while the generation is in flight: the provider takes 1.5 s, the withdrawal starts as soon as the
// generating process is inside the provider call.
\mod_sideline\local\consent\consent_manager::record_decision($sideline, (int) $student->id, true, $context->id, time());
$signal = make_request_directory() . '/in_provider';
$results = sideline_race([
    ['action' => 'generate', 'instance' => $sideline->id, 'user' => $student->id, 'signal' => $signal],
    ['action' => 'withdraw', 'instance' => $sideline->id, 'user' => $student->id, 'waitfor' => $signal],
]);
$left = $DB->count_records('sideline_cache', ['sidelineid' => $sideline->id, 'userid' => $student->id]);
$check(
    'withdrawal during generation',
    ($results[0]['status'] ?? '') === 'consent_required' && $left === 0,
    'generation returned ' . json_encode($results[0]) . ", $left cached rows left"
);

// The same with the withdrawal after the generation finished: delivered before, deleted after.
\mod_sideline\local\consent\consent_manager::record_decision($sideline, (int) $student->id, true, $context->id, time());
$results = sideline_race([
    ['action' => 'generate', 'instance' => $sideline->id, 'user' => $student->id],
    ['action' => 'withdraw', 'instance' => $sideline->id, 'user' => $student->id, 'delay' => 4000],
]);
$left = $DB->count_records('sideline_cache', ['sidelineid' => $sideline->id, 'userid' => $student->id]);
$check('withdrawal after generation', $left === 0, 'generation returned ' . json_encode($results[0]) . ", $left cached rows left");

// 3. Invalidation while the generation is in flight: the text is computed again, never stored stale.
\mod_sideline\local\consent\consent_manager::record_decision($sideline, (int) $student->id, true, $context->id, time());
$DB->delete_records('sideline_cache', ['sidelineid' => $sideline->id]);
// A row must exist, so that the invalidation has something to mark.
$DB->insert_record('sideline_cache', (object) ['sidelineid' => $sideline->id, 'userid' => $student->id, 'fingerprint' => 'old',
    'source' => 'template', 'commentary' => '{}', 'dirty' => 1, 'nexttransition' => 0, 'stamp' => 0, 'timecreated' => time(),
    'timemodified' => time()]);
$signal = make_request_directory() . '/in_provider';
$results = sideline_race([
    ['action' => 'generate', 'instance' => $sideline->id, 'user' => $student->id, 'signal' => $signal],
    ['action' => 'invalidate', 'instance' => $sideline->id, 'user' => $student->id, 'waitfor' => $signal],
]);
$row = $DB->get_record('sideline_cache', ['sidelineid' => $sideline->id, 'userid' => $student->id]);
$check(
    'invalidation during generation',
    ($results[0]['attempts'] ?? 0) === 2 && $row && ($row->fingerprint !== 'old'),
    'generation returned ' . json_encode($results[0]) . ', cache ' . ($row ? 'dirty=' . $row->dirty : 'empty')
);

echo $failures ? "$failures race(s) failed.\n" : "All races held.\n";
exit($failures ? 1 : 0);
