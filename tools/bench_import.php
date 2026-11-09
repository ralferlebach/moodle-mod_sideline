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
 * Cost of the configuration import with worst-case files of the maximum size. Development and CI sites only.
 *
 * Files, each just below config_transfer::MAX_BYTES unless it is meant to exceed a limit:
 *   wide      every list at its limit: 1000 activities, 20 rules with 10 conditions, 100 questions,
 *             10 courses, 5 categories, 1000 groups, 50 personas, texts padded to the size;
 *   deep      the nesting allowed (config_transfer::MAX_DEPTH) in rule conditions, padded to the size;
 *   tiny      as many empty objects as fit in the size: decoded completely, then refused by the count limit;
 *   oversize  one byte above the size: refused before decoding;
 *   toodeep   one level deeper than allowed: refused by the decoder.
 * Measured per step (decode with all checks, preview, apply) over --runs runs: wall time p50, p95, p99 and
 * maximum, CPU time p99, the peak memory of the step above the memory before it, and database queries.
 * The course has 200 activities and 100 groups, so that the references are resolved against real rows.
 * Budgets: decode p99 at most 250 ms, 16 MiB peak and no query; preview p99 at most 1000 ms, 16 MiB peak and 400 queries;
 * apply p99 at most 1000 ms, 16 MiB peak and 1000 queries. The queries are bounded by the limits of the file, never by
 * the size of the course.
 *
 * Usage: php tools/bench_import.php [--runs=10] [--json=file]
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/testing/generator/lib.php');
if (file_exists($CFG->libdir . '/testing/classes/util.php')) {
    require_once($CFG->libdir . '/testing/classes/util.php');
}

use mod_sideline\local\transfer\config_transfer;

[$options] = cli_get_params(['runs' => 10, 'json' => '', 'help' => false]);
if ($options['help'] || $CFG->wwwroot === 'https://moodle.example.org') {
    echo "php tools/bench_import.php [--runs=10] [--json=file]\n";
    exit(0);
}
\core\session\manager::set_user(get_admin());
$runs = max(1, (int) $options['runs']);
$budget = ['decode' => ['p99ms' => 250, 'peakmib' => 16, 'queries' => 0],
    'preview' => ['p99ms' => 1000, 'peakmib' => 16, 'queries' => 400],
    'apply' => ['p99ms' => 1000, 'peakmib' => 16, 'queries' => 1000]];

/**
 * Percentile of sorted values (nearest rank).
 *
 * @param float[] $sorted Sorted values.
 * @param float $p Percentile, 0 to 100.
 * @return float
 */
function bench_import_percentile(array $sorted, float $p): float {
    return $sorted[max(0, (int) ceil($p / 100 * count($sorted)) - 1)];
}

/**
 * CPU time of this process in milliseconds.
 *
 * @return float
 */
function bench_import_cpu(): float {
    $u = getrusage();
    return ($u['ru_utime.tv_sec'] + $u['ru_stime.tv_sec']) * 1000 + ($u['ru_utime.tv_usec'] + $u['ru_stime.tv_usec']) / 1000;
}

/**
 * Measure one step: wall time, CPU time, peak memory above the start, queries; and its result or error.
 *
 * @param callable $step The step.
 * @return array [ms, cpu ms, peak bytes, queries, result, error code]
 */
function bench_import_measure(callable $step): array {
    global $DB;
    gc_collect_cycles();
    if (function_exists('memory_reset_peak_usage')) {
        memory_reset_peak_usage();
    }
    $before = memory_get_usage();
    $queries = $DB->perf_get_queries();
    $cpu = bench_import_cpu();
    $start = hrtime(true);
    $result = null;
    $error = '';
    try {
        $result = $step();
    } catch (\moodle_exception $e) {
        $error = $e->errorcode;
    }
    return [(hrtime(true) - $start) / 1e6, bench_import_cpu() - $cpu, memory_get_peak_usage() - $before,
        $DB->perf_get_queries() - $queries, $result, $error];
}

/**
 * Pad a file with a long setting text until its JSON is just below the maximum size.
 *
 * @param array $data File content.
 * @param string $where Key in settings to pad.
 * @return string JSON.
 */
function bench_import_pad(array $data, string $where = 'persona'): string {
    $json = json_encode($data);
    $room = config_transfer::MAX_BYTES - strlen($json) - 16;
    if ($room > 0) {
        // Padding in strings of at most the allowed length.
        $data['questions'] = $data['questions'] ?? [];
        while ($room > 0 && count($data['questions']) < config_transfer::LIMITS['questions']) {
            $len = min(config_transfer::MAX_STRING, $room - 120);
            if ($len <= 0) {
                break;
            }
            $data['questions'][] = ['qtype' => 'text', 'prompt' => str_repeat('p', $len), 'config' => [], 'triggerkey' => 'any',
                'cooldown' => 0, 'enabled' => 0];
            $json = json_encode($data);
            $room = config_transfer::MAX_BYTES - strlen($json) - 16;
        }
    }
    return $json;
}

$gen = new testing_data_generator();
$stamp = time();
$course = $gen->create_course(['shortname' => "bi$stamp", 'enablecompletion' => 1]);
for ($i = 0; $i < 200; $i++) {
    $gen->create_module('page', ['course' => $course->id, 'name' => "Unit $i"]);
}
for ($i = 0; $i < 100; $i++) {
    $gen->create_group(['courseid' => $course->id, 'name' => "Group $i"]);
}
$sideline = $DB->get_record('sideline', ['id' => $gen->create_module('sideline', ['course' => $course->id])->id]);

$condition = static fn(int $i): array => ['type' => 'activity', 'field' => 'completion', 'is' => 'no',
    'activity' => ['modname' => 'page', 'name' => 'Unit ' . ($i % 250), 'section' => 0]];
$rules = [];
for ($r = 0; $r < config_transfer::LIMITS['rules']; $r++) {
    $rules[] = ['enabled' => 1, 'definition' => ['key' => "custom_bench$r", 'category' => 'next', 'priority' => 10,
        'match' => 'all', 'conditions' => array_map($condition, range($r * 10, $r * 10 + 9)), 'action' => 'open',
        'templates' => ['en' => str_repeat('Do not forget {label}. ', 12)]]];
}
$base = ['format' => config_transfer::FORMAT, 'version' => config_transfer::VERSION];
$wide = $base + [
    'settings' => [],
    'scope' => ['section' => 0, 'activities' => array_map(
        static fn(int $i): array => ['modname' => 'page', 'name' => 'Unit ' . ($i % 300), 'section' => 0],
        range(0, config_transfer::LIMITS['scope.activities'] - 1)
    )],
    'rules' => $rules,
    'questions' => [],
    'sources' => [
        'courses' => array_map(
            static fn(int $i): array => ['shortname' => "missing$i", 'idnumber' => ''],
            range(1, config_transfer::LIMITS['sources.courses'])
        ),
        'categories' => array_map(
            static fn(int $i): array => ['idnumber' => "cat$i", 'path' => ["Missing $i"]],
            range(1, config_transfer::LIMITS['sources.categories'])
        ),
    ],
    'groups' => ['all' => false, 'items' => array_map(
        static fn(int $i): array => ['type' => 'group', 'name' => 'Group ' . ($i % 150), 'idnumber' => ''],
        range(0, config_transfer::LIMITS['groups.items'] - 1)
    )],
    'personas' => array_map(static fn(int $i): array => ['key' => "site_bench$i"], range(1, config_transfer::LIMITS['personas'])),
];
// The deepest structure the decoder accepts (depth 16): arrays nested in a rule definition.
$nest = 'x';
for ($d = 0; $d < config_transfer::MAX_DEPTH - 5; $d++) {
    $nest = [$nest];
}
$deep = $base + ['rules' => array_map(static fn(array $rule): array => ['enabled' => 1, 'definition' => ['nest' => $nest]
    + $rule['definition']], $rules), 'settings' => [], 'scope' => [], 'questions' => [], 'sources' => [], 'groups' => [],
    'personas' => []];
$tiny = '{"format":"' . config_transfer::FORMAT . '","version":3,"groups":{"items":[';
$tiny .= rtrim(str_repeat('{},', intdiv(config_transfer::MAX_BYTES - strlen($tiny) - 8, 3)), ',') . ']}}';
$toodeep = json_encode($base + ['settings' => []]);
$toodeep = substr($toodeep, 0, -1) . ',"x":' . str_repeat('[', config_transfer::MAX_DEPTH) . str_repeat(
    ']',
    config_transfer::MAX_DEPTH
) . '}';
$files = [
    'wide' => bench_import_pad($wide),
    'deep' => bench_import_pad($deep),
    'tiny' => $tiny,
    'oversize' => str_pad(json_encode($base), config_transfer::MAX_BYTES + 1, ' '),
    'toodeep' => $toodeep,
];
$expected = ['wide' => '', 'deep' => '', 'tiny' => 'err_transfer_limit', 'oversize' => 'err_transfer_limit',
    'toodeep' => 'err_transfer_limit'];

$failures = 0;
$report = ['budget' => $budget, 'runs' => $runs, 'db' => $DB->get_dbfamily(), 'dbversion' => $DB->get_server_info()['version'],
    'php' => PHP_VERSION, 'files' => []];
printf(
    "%-9s %-8s %-7s %-7s %-7s %-7s %-7s %-7s %-6s %s\n",
    'file',
    'step',
    'KiB',
    'p50',
    'p95',
    'p99',
    'cpu99',
    'peakMiB',
    'qry',
    'result'
);
foreach ($files as $name => $json) {
    $steps = ['decode' => [], 'preview' => [], 'apply' => []];
    $outcome = '';
    for ($r = 0; $r < $runs; $r++) {
        $m = bench_import_measure(static fn() => config_transfer::decode($json));
        $steps['decode'][] = $m;
        $outcome = $m[5];
        if ($m[5] !== '') {
            continue;
        }
        $data = $m[4];
        $fresh = $DB->get_record('sideline', ['id' => $sideline->id]);
        $p = bench_import_measure(static fn() => config_transfer::preview($fresh, $data, config_transfer::PARTS, 'replace'));
        $steps['preview'][] = $p;
        $plan = $p[4]['plan'] ?? null;
        if ($plan) {
            $steps['apply'][] = bench_import_measure(static function () use ($fresh, $plan) {
                config_transfer::apply($fresh, $plan);
                return true;
            });
        }
    }
    foreach ($steps as $step => $samples) {
        if (!$samples) {
            continue;
        }
        $wall = array_column($samples, 0);
        $cpu = array_column($samples, 1);
        sort($wall);
        sort($cpu);
        $row = [
            'kib' => round(strlen($json) / 1024, 1),
            'p50' => round(bench_import_percentile($wall, 50), 1),
            'p95' => round(bench_import_percentile($wall, 95), 1),
            'p99' => round(bench_import_percentile($wall, 99), 1),
            'max' => round(end($wall), 1),
            'cpup99' => round(bench_import_percentile($cpu, 99), 1),
            'peakmib' => round(max(array_column($samples, 2)) / 1048576, 2),
            'queries' => max(array_column($samples, 3)),
            'result' => $step === 'decode' ? ($outcome === '' ? 'accepted' : $outcome) : 'done',
        ];
        $ok = $row['p99'] <= $budget[$step]['p99ms'] && $row['peakmib'] <= $budget[$step]['peakmib']
            && $row['queries'] <= $budget[$step]['queries']
            && ($step !== 'decode' || $outcome === $expected[$name]);
        $failures += $ok ? 0 : 1;
        $report['files'][$name][$step] = $row + ['ok' => $ok];
        printf(
            "%-9s %-8s %-7.1f %-7.1f %-7.1f %-7.1f %-7.1f %-7.2f %-6d %s%s\n",
            $name,
            $step,
            $row['kib'],
            $row['p50'],
            $row['p95'],
            $row['p99'],
            $row['cpup99'],
            $row['peakmib'],
            $row['queries'],
            $row['result'],
            $ok ? '' : '  OVER BUDGET'
        );
    }
}
if ($options['json'] !== '') {
    file_put_contents($options['json'], json_encode($report, JSON_PRETTY_PRINT));
}
echo $failures ? "$failures step(s) over budget or with an unexpected result.\n" : "All steps within budget.\n";
exit($failures ? 1 : 0);
