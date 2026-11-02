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
 * Query profile of the group path (issue #7, P-39, N-02). Development and CI sites only.
 *
 * For every combination of enrolled users (default 100, 1000, 5000) and groups (0, 10, 50, 200) it
 * builds a course in which every learner shares and has consented, a third completed an activity
 * yesterday, and measures the group statements of one recipient: database reads, wall time and
 * peak memory, cold (empty Moodle caches, so core rebuilds its group, course and capability caches)
 * and warm (only the plugin's own queries). Budgets per call: at most 12 reads warm, 300 ms cold.
 *
 * Usage: php tools/bench_groups.php [--users=100,1000,5000] [--groups=0,10,50,200]
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

[$options] = cli_get_params(['users' => '100,1000,5000', 'groups' => '0,10,50,200', 'help' => false]);
if ($options['help'] || $CFG->wwwroot === 'https://moodle.example.org') {
    echo "php tools/bench_groups.php [--users=100,1000,5000] [--groups=0,10,50,200]\n";
    exit(0);
}
\core\session\manager::set_user(get_admin());
set_config('enablecompletion', 1);
$gen = new testing_data_generator();
$stamp = time();
$budgetreads = 12;
$budgetms = 300;
$failures = 0;

printf("%-6s %-7s %-9s %-9s %-8s %-8s %-9s %s\n", 'users', 'groups', 'reads', 'reads2', 'ms', 'ms2', 'peakMB', 'statement');
foreach (array_map('intval', explode(',', $options['users'])) as $size) {
    $course = $gen->create_course(['shortname' => "bg{$stamp}_$size", 'enablecompletion' => 1]);
    $page = $gen->create_module('page', ['course' => $course->id, 'name' => 'Unit A', 'completion' => COMPLETION_TRACKING_MANUAL]);
    $instance = $gen->create_module('sideline', ['course' => $course->id, 'sharingmode' => 'closed', 'grouppublish' => 'general',
        'consentrequired' => 1]);
    $userids = [];
    $enrol = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
    $plugin = enrol_get_plugin('manual');
    $role = $DB->get_field('role', 'id', ['shortname' => 'student']);
    for ($i = 0; $i < $size; $i++) {
        $user = $gen->create_user(['username' => "bg{$stamp}_{$size}_$i"]);
        $plugin->enrol_user($enrol, $user->id, $role);
        $userids[] = (int) $user->id;
    }
    $recipient = $userids[0];
    $sideline = $DB->get_record('sideline', ['id' => $instance->id]);
    $consent = [];
    $publish = [];
    $completion = [];
    foreach ($userids as $i => $userid) {
        $consent[] = ['sidelineid' => $sideline->id, 'userid' => $userid, 'status' => 'accepted',
            'infoversion' => (int) $sideline->consentversion, 'timecreated' => $stamp, 'timemodified' => $stamp];
        $publish[] = ['sidelineid' => $sideline->id, 'userid' => $userid, 'publish' => 1, 'named' => 0, 'infoversion' => 99,
            'timecreated' => $stamp, 'timemodified' => $stamp];
        if ($i % 3 === 1) {
            $completion[] = ['coursemoduleid' => $page->cmid, 'userid' => $userid, 'completionstate' => COMPLETION_COMPLETE,
                'overrideby' => null, 'timemodified' => \mod_sideline\local\group\group_service::day_start(time()) - HOURSECS];
        }
    }
    $DB->insert_records('sideline_consent', $consent);
    $DB->insert_records('sideline_publish', $publish);
    $DB->insert_records('course_modules_completion', $completion);

    foreach (array_map('intval', explode(',', $options['groups'])) as $groups) {
        $DB->delete_records_select('groups_members', 'groupid IN (SELECT id FROM {groups} WHERE courseid = ?)', [$course->id]);
        $DB->delete_records('groups', ['courseid' => $course->id]);
        $members = [];
        $groupids = [];
        for ($g = 0; $g < $groups; $g++) {
            $groupids[] = (int) $DB->insert_record('groups', (object) ['courseid' => $course->id, 'name' => "G$g",
                'timecreated' => $stamp, 'timemodified' => $stamp, 'descriptionformat' => 1]);
        }
        foreach ($groupids ? $userids : [] as $i => $userid) {
            $members[] = ['groupid' => $groupids[$i % count($groupids)], 'userid' => $userid, 'timeadded' => $stamp];
        }
        if ($members) {
            // The recipient is also in a second group, as learners often are.
            $members[] = ['groupid' => $groupids[min(1, count($groupids) - 1)], 'userid' => $recipient, 'timeadded' => $stamp];
            $DB->insert_records('groups_members', array_values(array_unique($members, SORT_REGULAR)));
        }
        // Bulk inserts leave PostgreSQL without statistics until autovacuum runs; a live site has them.
        if ($DB->get_dbfamily() === 'postgres') {
            $tables = ['groups', 'groups_members', 'user', 'user_enrolments', 'sideline_publish', 'sideline_consent',
                'course_modules_completion'];
            foreach ($tables as $table) {
                $DB->execute('ANALYZE {' . $table . '}');
            }
        }
        // Groups were written directly: drop the group caches the way the group API does it.
        \cache_helper::invalidate_by_definition('core', 'groupdata', [], [$course->id]);
        \cache_helper::invalidate_by_definition('core', 'user_group_groupings', [], $userids);
        \cache_helper::purge_all();
        $sideline = $DB->get_record('sideline', ['id' => $instance->id]);
        $cms = get_fast_modinfo($course, $recipient)->get_cms();
        $service = new \mod_sideline\local\group\group_service();
        $runs = [];
        foreach (['cold', 'warm'] as $kind) {
            $reads = $DB->perf_get_reads();
            $start = microtime(true);
            [$facts] = $service->facts($sideline, $recipient, $cms, time());
            $runs[$kind] = [$DB->perf_get_reads() - $reads, (microtime(true) - $start) * 1000, $facts];
        }
        $statement = $runs['warm'][2] ? $runs['warm'][2][0]['key'] . '/' . ($runs['warm'][2][0]['band'] ?? '') : '-';
        $ok = $runs['warm'][0] <= $budgetreads && $runs['cold'][1] <= $budgetms;
        $failures += $ok ? 0 : 1;
        printf(
            "%-6d %-7d %-9d %-9d %-8.1f %-8.1f %-9.1f %s%s\n",
            $size,
            $groups,
            $runs['cold'][0],
            $runs['warm'][0],
            $runs['cold'][1],
            $runs['warm'][1],
            memory_get_peak_usage(true) / 1048576,
            $statement,
            $ok ? '' : '  OVER BUDGET'
        );
    }
}
echo $failures ? "$failures combination(s) over budget.\n"
    : "All combinations within budget ($budgetreads reads warm, $budgetms ms cold).\n";
exit($failures ? 1 : 0);
