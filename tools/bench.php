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
 * Load and cache measurement for mod_sideline (P1-13). Development sites only.
 *
 * Creates a course with activities and learners, then measures per request: wall time and
 * database reads, for the first request (generation), a repeat (cache hit), a mixed workload
 * with state changes, and optionally parallel workers on the same learners (singleflight).
 *
 * Usage:
 *   php tools/bench.php --users=200 --activities=30 --requests=1000 [--workers=8] [--cleanup]
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->libdir . '/testing/generator/lib.php');
// Moodle 4.5 keeps testing_util in this file; 5.x autoloads it (\core\test\testing_util and an alias).
if (file_exists($CFG->libdir . '/testing/classes/util.php')) {
    require_once($CFG->libdir . '/testing/classes/util.php');
}

[$options] = cli_get_params([
    'users' => 200, 'activities' => 30, 'requests' => 1000, 'workers' => 0, 'worker' => 0, 'courseid' => 0,
    'cleanup' => false, 'help' => false,
]);
if ($options['help'] || $CFG->wwwroot === 'https://moodle.example.org') {
    echo "php tools/bench.php --users=200 --activities=30 --requests=1000 [--workers=8] [--cleanup]\n";
    exit(0);
}
\core\session\manager::set_user(get_admin());
set_config('enablecompletion', 1);

/**
 * Run one request as a user and measure it.
 *
 * @param stdClass $sideline Instance.
 * @param stdClass $course Course.
 * @param stdClass $user User.
 * @param context_module $context Context.
 * @return array [seconds, reads, source]
 */
function sideline_bench_request(stdClass $sideline, stdClass $course, stdClass $user, context_module $context): array {
    global $DB;
    \core\session\manager::set_user($user);
    $reads = $DB->perf_get_reads();
    $start = microtime(true);
    $result = (new \mod_sideline\local\service\commentary_service())->get($sideline, $course, $user, $context, time());
    return [microtime(true) - $start, $DB->perf_get_reads() - $reads, $result['commentary']['source'] ?? ''];
}

/**
 * Summary statistics.
 *
 * @param float[] $values Values.
 * @return string
 */
function sideline_bench_stats(array $values): string {
    sort($values);
    $n = count($values);
    $p = static fn(float $q) => $values[min($n - 1, (int) floor($q * $n))];
    return sprintf(
        'n=%d mean=%.2f p50=%.2f p95=%.2f max=%.2f',
        $n,
        array_sum($values) / max(1, $n),
        $p(0.5),
        $p(0.95),
        end($values),
    );
}

$generator = testing_util::get_data_generator();

if ($options['worker']) {
    // Parallel worker: request random learners of an existing course.
    $course = get_course((int) $options['courseid']);
    $cm = get_fast_modinfo($course)->get_instances_of('sideline');
    $cm = reset($cm);
    $sideline = $DB->get_record('sideline', ['id' => $cm->instance]);
    $context = context_module::instance($cm->id);
    $users = array_values(get_enrolled_users(context_course::instance($course->id), '', 0, 'u.*'));
    $times = [];
    $errors = 0;
    for ($i = 0; $i < (int) $options['requests']; $i++) {
        try {
            [$t] = sideline_bench_request($sideline, $course, $users[array_rand($users)], $context);
            $times[] = $t * 1000;
        } catch (Throwable $e) {
            $errors++;
        }
    }
    echo json_encode(['times' => $times, 'errors' => $errors]), "\n";
    exit(0);
}

mtrace('Creating course with ' . $options['activities'] . ' activities and ' . $options['users'] . ' learners...');
$course = $generator->create_course(['enablecompletion' => 1, 'fullname' => 'Sideline bench ' . time()]);
$pages = [];
for ($i = 1; $i <= (int) $options['activities']; $i++) {
    $pages[] = $generator->create_module('page', ['course' => $course->id, 'name' => 'Unit ' . $i,
        'completion' => COMPLETION_TRACKING_MANUAL]);
}
$instance = $generator->create_module('sideline', ['course' => $course->id, 'display' => 'page']);
$sideline = $DB->get_record('sideline', ['id' => $instance->id]);
$context = context_module::instance($instance->cmid);
$users = [];
for ($i = 1; $i <= (int) $options['users']; $i++) {
    $users[] = $generator->create_and_enrol($course, 'student');
}
$completion = new completion_info($course);
// Some progress for half of the learners.
foreach ($users as $index => $user) {
    if ($index % 2) {
        $completion->update_state(
            get_coursemodule_from_instance('page', $pages[$index % count($pages)]->id),
            COMPLETION_COMPLETE,
            $user->id
        );
    }
}

$cold = [];
$coldreads = [];
$warm = [];
$warmreads = [];
foreach ($users as $user) {
    [$t, $r] = sideline_bench_request($sideline, $course, $user, $context);
    $cold[] = $t * 1000;
    $coldreads[] = $r;
    [$t, $r] = sideline_bench_request($sideline, $course, $user, $context);
    $warm[] = $t * 1000;
    $warmreads[] = $r;
}
mtrace('First request (generation), ms:   ' . sideline_bench_stats($cold));
mtrace('First request, DB reads:          ' . sideline_bench_stats($coldreads));
mtrace('Repeat (cache hit), ms:           ' . sideline_bench_stats($warm));
mtrace('Repeat, DB reads:                 ' . sideline_bench_stats($warmreads));

// Mixed workload: 10 % of requests follow a state change of that learner.
$hits = 0;
$mixed = [];
for ($i = 0; $i < (int) $options['requests']; $i++) {
    $user = $users[array_rand($users)];
    if (random_int(1, 10) === 1) {
        \core\session\manager::set_user(get_admin());
        $completion->update_state(
            get_coursemodule_from_instance('page', $pages[array_rand($pages)]->id),
            COMPLETION_COMPLETE,
            $user->id
        );
    }
    $row = $DB->get_record('sideline_cache', ['sidelineid' => $sideline->id, 'userid' => $user->id], 'dirty, timemodified');
    [$t] = sideline_bench_request($sideline, $course, $user, $context);
    $after = $DB->get_record('sideline_cache', ['sidelineid' => $sideline->id, 'userid' => $user->id], 'dirty, timemodified');
    if ($row && !$row->dirty && $after && (int) $after->timemodified === (int) $row->timemodified) {
        $hits++;
    }
    $mixed[] = $t * 1000;
}
mtrace('Mixed workload, ms:               ' . sideline_bench_stats($mixed));
mtrace(sprintf('Mixed workload, cache hit rate:   %.1f %%', 100 * $hits / max(1, (int) $options['requests'])));

if ((int) $options['workers'] > 0) {
    $DB->delete_records('sideline_cache', ['sidelineid' => $sideline->id]);
    $procs = [];
    $per = max(1, intdiv((int) $options['requests'], (int) $options['workers']));
    $start = microtime(true);
    for ($w = 1; $w <= (int) $options['workers']; $w++) {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
            . " --worker=$w --courseid={$course->id} --requests=$per";
        $procs[] = popen($cmd, 'r');
    }
    $all = [];
    $errors = 0;
    foreach ($procs as $proc) {
        $data = json_decode((string) stream_get_contents($proc), true);
        pclose($proc);
        $all = array_merge($all, $data['times'] ?? []);
        $errors += (int) ($data['errors'] ?? 1);
    }
    $elapsed = microtime(true) - $start;
    mtrace(sprintf(
        'Parallel: %d workers, %d requests in %.1f s (%.0f requests/s), errors: %d',
        (int) $options['workers'],
        count($all),
        $elapsed,
        count($all) / max(0.001, $elapsed),
        $errors
    ));
    mtrace('Parallel, ms:                     ' . sideline_bench_stats($all));
    mtrace('Cache rows after parallel run:    ' . $DB->count_records('sideline_cache', ['sidelineid' => $sideline->id])
        . ' (one per learner at most, unique index)');
}

if ($options['cleanup']) {
    \core\session\manager::set_user(get_admin());
    delete_course($course, false);
    mtrace('Course deleted.');
}
