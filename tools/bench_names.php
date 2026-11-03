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
 * or ended) it measures one check of a free text of the maximum length that contains no name, the
 * worst case, because every query runs: database reads, wall time and the memory the check keeps.
 * Then it checks that a name of the last person enrolled is found. Budgets per check: at most 3 reads,
 * 200 ms, 256 KiB of memory.
 *
 * Usage: php tools/bench_names.php [--sizes=100,1000,5000,20000]
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

[$options] = cli_get_params(['sizes' => '100,1000,5000,20000', 'help' => false]);
if ($options['help'] || $CFG->wwwroot === 'https://moodle.example.org') {
    echo "php tools/bench_names.php [--sizes=100,1000,5000,20000]\n";
    exit(0);
}
\core\session\manager::set_user(get_admin());
$gen = new testing_data_generator();
$stamp = time();
$budgetreads = 3;
$budgetms = 200;
$budgetbytes = 256 * 1024;
$failures = 0;
// The worst case: as long as allowed and only different short words, so that it has the most phrases and
// every query runs. Two letters each ("ab cd ..."), no name of the course among them.
$text = '';
for ($i = 0; mb_strlen($text) + 3 <= prompt_text_guard::MAX_LENGTH; $i++) {
    $text .= chr(97 + intdiv($i, 26) % 26) . chr(97 + $i % 26) . ' ';
}
$text = trim($text);

printf("%-7s %-6s %-8s %-8s %-9s %s\n", 'size', 'reads', 'ms', 'ms2', 'keptKiB', 'found');
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
    $runs = [];
    foreach (['first', 'second'] as $kind) {
        prompt_text_guard::reset();
        gc_collect_cycles();
        $memory = memory_get_usage();
        $reads = $DB->perf_get_reads();
        $start = microtime(true);
        $result = prompt_text_guard::course_name($text, (int) $course->id);
        $runs[$kind] = [$DB->perf_get_reads() - $reads, (microtime(true) - $start) * 1000, memory_get_usage() - $memory, $result];
    }
    $last = $size - 1;
    $found = prompt_text_guard::course_name("Ask lastname$last about it.", (int) $course->id) === 'name';
    $ok = $runs['first'][3] === null && $found && $runs['first'][0] <= $budgetreads && $runs['first'][1] <= $budgetms
        && $runs['first'][2] <= $budgetbytes;
    $failures += $ok ? 0 : 1;
    printf(
        "%-7d %-6d %-8.1f %-8.1f %-9.1f %s%s\n",
        $size,
        $runs['first'][0],
        $runs['first'][1],
        $runs['second'][1],
        $runs['first'][2] / 1024,
        $found ? 'yes' : 'NO',
        $ok ? '' : '  OVER BUDGET'
    );
}
echo $failures ? "$failures size(s) over budget.\n"
    : "All sizes within budget ($budgetreads reads, $budgetms ms, " . ($budgetbytes / 1024) . " KiB per check).\n";
exit($failures ? 1 : 0);
