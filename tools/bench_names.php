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
 * Cost of the name check of configured free text (prompt_text_guard::course_name). Development and CI sites only.
 *
 * For every course size (default 100, 1000, 5000, 20000 enrolments, half of them historical: suspended
 * or ended) it runs a set of adversarial free texts of the maximum length many times (--runs, default 30
 * per text) and reports the distribution of one check: database queries and their time, wall time
 * (p50, p95, p99, maximum), CPU time and the peak memory of the check above the memory before it.
 * Every check starts without the request cache of the guard; the database is warm after the first run.
 * The texts:
 *   distinct  only different words of two letters: the most phrases, every query runs (no name in it);
 *   unicode   different words with umlauts, accents, combining marks and Cyrillic letters;
 *   nearmiss  words that differ from enrolled names by one character (firstname12x, lastname1y ...);
 *   hyphens   hyphenated pairs, so that every word also forms phrases with its neighbours;
 *   repeated  one word repeated (few phrases, the cheap case of real texts);
 *   letters   words of one letter or digit in an order where almost every sequence differs: the most phrases;
 *   namelast  the distinct text with the last name of the last person enrolled at its end (found).
 * A text above the maximum length is refused before any query (checked once).
 * Budgets per check: at most 2 queries, p99 at most 200 ms, peak memory at most 1 MiB, and the name at
 * the end is found.
 *
 * For every size it also prints the execution plan of the name query for the largest chunk of phrases (the
 * distinct text), as the database runs it: EXPLAIN (ANALYZE, BUFFERS)
 * on PostgreSQL, ANALYZE on MariaDB, EXPLAIN ANALYZE on MySQL. The plan shows which indexes bound the scan
 * (user_enrolments.enrolid with the enrolment methods of the course, user.id); it is recorded, not judged (--json
 * holds it as "plan").
 *
 * --siteusers=N adds N users before the courses are built, enrolled nowhere, or with --siteenrol spread over 100
 * other courses (N enrolments more). The query compares the names of the course's enrolments only, but the database
 * may scan the user or the enrolment table when that looks cheaper, so the cost can grow with the size of the site
 * rather than of the course. The plans with and without them show which it is.
 *
 * Usage: php tools/bench_names.php [--sizes=100,1000,5000,20000] [--runs=30] [--json=file] [--noplan]
 *     [--siteusers=N [--siteenrol]]
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

use mod_sideline\local\ai\prompt_text_guard;

[$options] = cli_get_params(['sizes' => '100,1000,5000,20000', 'runs' => 30, 'json' => '', 'noplan' => false,
    'siteusers' => 0, 'siteenrol' => false, 'help' => false]);
if ($options['help'] || $CFG->wwwroot === 'https://moodle.example.org') {
    echo "php tools/bench_names.php [--sizes=100,1000,5000,20000] [--runs=30] [--json=file] [--noplan] [--siteusers=N]\n";
    exit(0);
}
\core\session\manager::set_user(get_admin());
$gen = new testing_data_generator();
$stamp = time();
$runs = max(1, (int) $options['runs']);
$budget = ['queries' => 2, 'p99ms' => 200, 'peakkib' => 1024];
$failures = 0;

// Users of the rest of the site, enrolled nowhere.
$siteusers = max(0, (int) $options['siteusers']);
for ($done = 0; $done < $siteusers; $done += 1000) {
    $rows = [];
    for ($i = $done; $i < min($siteusers, $done + 1000); $i++) {
        $rows[] = ['auth' => 'manual', 'confirmed' => 1, 'mnethostid' => $CFG->mnet_localhost_id,
            'username' => "bn{$stamp}_site_$i", 'firstname' => "Sitefirst$i", 'lastname' => "Sitelast$i",
            'email' => "bns$i@example.invalid", 'timecreated' => $stamp, 'timemodified' => $stamp];
    }
    $DB->insert_records('user', $rows);
}
if ($siteusers && $options['siteenrol']) {
    $enrolids = [];
    for ($c = 0; $c < 100; $c++) {
        $other = $gen->create_course(['shortname' => "bn{$stamp}_site_course_$c"]);
        $enrolids[] = (int) $DB->get_field('enrol', 'id', ['courseid' => $other->id, 'enrol' => 'manual'], MUST_EXIST);
    }
    $ids = $DB->get_fieldset_select('user', 'id', $DB->sql_like('username', ':u'), ['u' => "bn{$stamp}_site_%"]);
    foreach (array_chunk(array_values($ids), 5000, true) as $chunk) {
        $rows = [];
        foreach ($chunk as $i => $userid) {
            $rows[] = ['enrolid' => $enrolids[$i % 100], 'userid' => $userid, 'status' => 0, 'timestart' => 0, 'timeend' => 0,
                'modifierid' => 2, 'timecreated' => $stamp, 'timemodified' => $stamp];
        }
        $DB->insert_records('user_enrolments', $rows);
    }
}
$siteusers = $DB->count_records('user', ['deleted' => 0]);
$siteenrolments = $DB->count_records('user_enrolments');

/**
 * Fill a text up to the maximum length with words from a generator.
 *
 * @param callable $word Word number => word.
 * @param string $glue Separator.
 * @return string
 */
function bench_names_fill(callable $word, string $glue = ' '): string {
    $text = '';
    for ($i = 0;; $i++) {
        $next = ($text === '' ? '' : $text . $glue) . $word($i);
        if (mb_strlen($next) > prompt_text_guard::MAX_LENGTH) {
            return $text;
        }
        $text = $next;
    }
}

/**
 * Percentile of sorted values (nearest rank).
 *
 * @param float[] $sorted Sorted values.
 * @param float $p Percentile, 0 to 100.
 * @return float
 */
function bench_names_percentile(array $sorted, float $p): float {
    return $sorted[max(0, (int) ceil($p / 100 * count($sorted)) - 1)];
}

/**
 * CPU time of this process in milliseconds.
 *
 * @return float
 */
function bench_names_cpu(): float {
    $u = getrusage();
    return ($u['ru_utime.tv_sec'] + $u['ru_stime.tv_sec']) * 1000 + ($u['ru_utime.tv_usec'] + $u['ru_stime.tv_usec']) / 1000;
}

/**
 * The execution plan of a query, as the database runs it, one line per step: the full plan is JSON
 * (PostgreSQL and MariaDB), so that a cursor or duplicate lines cannot lose a part of it.
 *
 * @param string $sql Query with Moodle table names and named parameters.
 * @param array $params Parameters.
 * @return string[] Lines of the plan.
 */
function bench_names_plan(string $sql, array $params): array {
    global $DB;
    if ($DB->get_dbfamily() === 'postgres') {
        $raw = $DB->get_field_sql('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) ' . $sql, $params);
    } else if ($DB->get_dbfamily() === 'mysql' && $DB->get_dbvendor() === 'mariadb') {
        $raw = $DB->get_field_sql('ANALYZE FORMAT=JSON ' . $sql, $params);
    } else if ($DB->get_dbfamily() === 'mysql') {
        return explode("\n", (string) $DB->get_field_sql('EXPLAIN ANALYZE ' . $sql, $params));
    } else {
        return ['no plan for ' . $DB->get_dbfamily()];
    }
    $lines = [];
    $walk = static function ($node, int $depth) use (&$walk, &$lines): void {
        if (!is_array($node)) {
            return;
        }
        if (isset($node['Node Type'])) {
            $lines[] = str_repeat('  ', $depth) . $node['Node Type']
                . (isset($node['Relation Name']) ? ' on ' . $node['Relation Name'] : '')
                . (isset($node['Index Name']) ? ' using ' . $node['Index Name'] : '')
                . sprintf(' rows=%s time=%.2fms', $node['Actual Rows'] ?? '?', $node['Actual Total Time'] ?? 0)
                . (isset($node['Shared Hit Blocks'])
                    ? ' hit=' . $node['Shared Hit Blocks'] . ' read=' . $node['Shared Read Blocks'] : '');
            $depth++;
        } else if (isset($node['table_name'])) {
            $lines[] = str_repeat('  ', $depth) . 'table ' . $node['table_name'] . ' access=' . ($node['access_type'] ?? '?')
                . ' key=' . ($node['key'] ?? 'none') . ' r_rows=' . ($node['r_rows'] ?? '?')
                . sprintf(' time=%.2fms', $node['r_table_time_ms'] ?? $node['r_total_time_ms'] ?? 0);
        }
        foreach ($node as $child) {
            $walk($child, $depth);
        }
    };
    $walk(json_decode((string) $raw, true), 0);
    return $lines;
}

$two = static fn(int $i): string => chr(97 + intdiv($i, 26) % 26) . chr(97 + $i % 26);
$letters = ['ä', 'é', 'ø', 'ñ', 'ş', 'ж', 'ю', 'ő', 'ł', 'å'];
$symbols = str_split('abcdefghijklmnopqrstuvwxyz0123456789');
$texts = [
    'distinct' => bench_names_fill($two),
    'unicode' => bench_names_fill(static fn(int $i): string => $letters[$i % 10] . $two($i) . "\u{0301}"),
    'nearmiss' => bench_names_fill(static fn(int $i): string => ($i % 2 ? 'Lastname' : 'Firstname') . $i . 'x'),
    'hyphens' => bench_names_fill(static fn(int $i): string => $two(2 * $i) . '-' . $two(2 * $i + 1)),
    'repeated' => bench_names_fill(static fn(int $i): string => 'again'),
    // A fixed pseudo-random order of the 36 symbols: 250 words and over 700 different phrases.
    'letters' => bench_names_fill(static fn(int $i): string => $symbols[hexdec(substr(md5((string) $i), 0, 6)) % 36]),
];

printf(
    "%-6s %-9s %-4s %-6s %-7s %-7s %-7s %-7s %-7s %-8s %s\n",
    'size',
    'text',
    'qry',
    'dbms',
    'p50',
    'p95',
    'p99',
    'max',
    'cpu99',
    'peakKiB',
    'result'
);
$report = ['budget' => $budget, 'runs' => $runs, 'siteusers' => $siteusers, 'siteenrolments' => $siteenrolments,
    'db' => $DB->get_dbfamily(),
    'dbversion' => $DB->get_server_info()['version'], 'php' => PHP_VERSION, 'sizes' => []];
foreach (array_map('intval', explode(',', $options['sizes'])) as $size) {
    $course = $gen->create_course(['shortname' => "bn{$stamp}_$size"]);
    $enrol = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
    $users = [];
    for ($i = 0; $i < $size; $i++) {
        $users[] = ['auth' => 'manual', 'confirmed' => 1, 'mnethostid' => $CFG->mnet_localhost_id,
            'username' => "bn{$stamp}_{$size}_$i", 'firstname' => "Firstname$i", 'lastname' => "Lastname$i",
            'email' => "bn$i@example.invalid", 'timecreated' => $stamp, 'timemodified' => $stamp];
    }
    foreach (array_chunk($users, 1000) as $chunk) {
        $DB->insert_records('user', $chunk);
    }
    $ids = $DB->get_fieldset_select('user', 'id', $DB->sql_like('username', ':u'), ['u' => "bn{$stamp}_{$size}_%"]);
    $enrolments = [];
    foreach (array_values($ids) as $i => $userid) {
        // Half of them historical: suspended, or the enrolment ended a year ago.
        $enrolments[] = ['enrolid' => $enrol->id, 'userid' => $userid, 'status' => $i % 4 === 1 ? 1 : 0,
            'timestart' => 0, 'timeend' => $i % 4 === 3 ? $stamp - YEARSECS : 0, 'modifierid' => 2,
            'timecreated' => $stamp, 'timemodified' => $stamp];
    }
    foreach (array_chunk($enrolments, 1000) as $chunk) {
        $DB->insert_records('user_enrolments', $chunk);
    }
    if ($DB->get_dbfamily() === 'postgres') {
        foreach (['user', 'user_enrolments', 'enrol'] as $table) {
            $DB->execute('ANALYZE {' . $table . '}');
        }
    }
    $last = $size - 1;
    $sizetexts = $texts + ['namelast' => mb_substr($texts['distinct'], 0, prompt_text_guard::MAX_LENGTH - 20) . " lastname$last"];
    $expected = ['namelast' => 'name'];
    if (!$options['noplan']) {
        $chunk = array_slice(prompt_text_guard::candidates($texts['distinct']), 0, prompt_text_guard::CHUNK);
        [$sql, $params] = prompt_text_guard::name_query(prompt_text_guard::enrol_ids([(int) $course->id]), $chunk);
        $plan = bench_names_plan($sql, $params);
        $report['plans'][$size] = ['phrases' => count($chunk), 'plan' => $plan];
        echo "Plan of the name query, $size enrolments; before this run the site had $siteusers users and $siteenrolments"
            . " enrolments; "
            . count($chunk) . " phrases:\n  "
            . implode("\n  ", $plan) . "\n";
    }
    $toolong = prompt_text_guard::course_name(str_repeat('a', prompt_text_guard::MAX_LENGTH + 1), (int) $course->id);
    if ($toolong !== 'length') {
        $failures++;
        echo "A text above the maximum length was not refused.\n";
    }
    foreach ($sizetexts as $name => $text) {
        $wall = [];
        $cpu = [];
        $peak = 0;
        $queries = 0;
        $dbms = 0.0;
        $results = [];
        for ($r = 0; $r < $runs; $r++) {
            prompt_text_guard::reset();
            gc_collect_cycles();
            if (function_exists('memory_reset_peak_usage')) {
                memory_reset_peak_usage();
            }
            $before = memory_get_usage();
            $q = $DB->perf_get_queries();
            $qt = $DB->perf_get_queries_time();
            $c = bench_names_cpu();
            $start = hrtime(true);
            $result = prompt_text_guard::course_name($text, (int) $course->id);
            $wall[] = (hrtime(true) - $start) / 1e6;
            $cpu[] = bench_names_cpu() - $c;
            $peak = max($peak, memory_get_peak_usage() - $before);
            $queries = max($queries, $DB->perf_get_queries() - $q);
            $dbms = max($dbms, ($DB->perf_get_queries_time() - $qt) * 1000);
            $results[$result ?? 'none'] = true;
        }
        sort($wall);
        sort($cpu);
        $row = [
            'queries' => $queries,
            'dbmsmax' => round($dbms, 1),
            'p50' => round(bench_names_percentile($wall, 50), 1),
            'p95' => round(bench_names_percentile($wall, 95), 1),
            'p99' => round(bench_names_percentile($wall, 99), 1),
            'max' => round(end($wall), 1),
            'cpup99' => round(bench_names_percentile($cpu, 99), 1),
            'peakkib' => round($peak / 1024, 1),
            'result' => implode('/', array_keys($results)),
        ];
        $ok = $row['queries'] <= $budget['queries'] && $row['p99'] <= $budget['p99ms'] && $row['peakkib'] <= $budget['peakkib']
            && $row['result'] === ($expected[$name] ?? 'none');
        $failures += $ok ? 0 : 1;
        $report['sizes'][$size][$name] = $row + ['ok' => $ok];
        printf(
            "%-6d %-9s %-4d %-6.1f %-7.1f %-7.1f %-7.1f %-7.1f %-7.1f %-8.1f %s%s\n",
            $size,
            $name,
            $row['queries'],
            $row['dbmsmax'],
            $row['p50'],
            $row['p95'],
            $row['p99'],
            $row['max'],
            $row['cpup99'],
            $row['peakkib'],
            $row['result'],
            $ok ? '' : '  OVER BUDGET'
        );
    }
}
if ($options['json'] !== '') {
    file_put_contents($options['json'], json_encode($report, JSON_PRETTY_PRINT));
}
echo $failures ? "$failures case(s) over budget.\n"
    : "All cases within budget ({$budget['queries']} queries, p99 {$budget['p99ms']} ms, "
        . "{$budget['peakkib']} KiB peak per check).\n";
exit($failures ? 1 : 0);
