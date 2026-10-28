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
 * Server-side checks after the HTTP load test (P-39): what k6 cannot see from outside.
 *
 * - every learner who requested commentary has exactly one cache row (unique index, singleflight);
 * - the race learner's row is current: not dirty after the concurrent requests;
 * - no cached commentary is empty.
 *
 * Usage: php tests/load/verify_load.php --cmid=N --prefix=slload..._
 * Exit code 0 when everything holds, 1 otherwise.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options] = cli_get_params(['cmid' => 0, 'prefix' => '']);
$cm = get_coursemodule_from_id('sideline', (int) $options['cmid'], 0, false, MUST_EXIST);
$rows = $DB->get_records_sql(
    'SELECT c.id, c.userid, c.dirty, c.commentary, u.username
       FROM {sideline_cache} c
       JOIN {user} u ON u.id = c.userid
      WHERE c.sidelineid = ?',
    [$cm->instance]
);

$problems = [];
$peruser = [];
foreach ($rows as $row) {
    $peruser[$row->userid] = ($peruser[$row->userid] ?? 0) + 1;
    $commentary = json_decode($row->commentary, true);
    if (empty($commentary['blocks'])) {
        $problems[] = "empty commentary for $row->username";
    }
}
foreach ($peruser as $userid => $count) {
    if ($count > 1) {
        $problems[] = "user $userid has $count cache rows";
    }
}
$race = $DB->get_record('user', ['username' => $options['prefix'] . '0']);
if ($race) {
    $racerow = $DB->get_record('sideline_cache', ['sidelineid' => $cm->instance, 'userid' => $race->id]);
    if (!$racerow) {
        $problems[] = 'the race learner has no cache row';
    } else if ((int) $racerow->dirty) {
        $problems[] = 'the race learner\'s cache row is still dirty';
    }
}

echo 'Cache rows: ' . count($rows) . ', learners: ' . count($peruser) . "\n";
if ($problems) {
    echo "FAILED:\n  " . implode("\n  ", $problems) . "\n";
    exit(1);
}
echo "OK\n";
