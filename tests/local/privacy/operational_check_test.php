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

namespace mod_sideline\local\privacy;

use mod_sideline\local\group\group_service;

#[\PHPUnit\Framework\Attributes\CoversClass(operational_check::class)]
/**
 * The operational privacy check reports what a site keeps and fails when retention does not run (issue #14).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_sideline\local\privacy\operational_check
 */
final class operational_check_test extends \advanced_testcase {
    /** @var int Fixed time of the check. */
    private const NOW = 1798000000;

    /**
     * Results by name.
     *
     * @return array name => [result, detail]
     */
    private function results(): array {
        $results = [];
        foreach (operational_check::run(self::NOW) as [$name, $result, $detail]) {
            $results[$name] = [$result, $detail];
        }
        return $results;
    }

    public function test_a_fresh_site_passes_every_check(): void {
        $this->resetAfterTest();
        set_config('airetentiondays', 90, 'mod_sideline');
        set_config('tracing', 0, 'mod_sideline');
        $results = $this->results();
        $this->assertCount(9, $results);
        foreach ($results as $name => [$result, $detail]) {
            $this->assertNotSame(operational_check::FAIL, $result, "$name: $detail");
        }
        $this->assertSame(operational_check::OK, $results['caches'][0], 'No cache of the plugin');
        $this->assertSame(operational_check::OK, $results['removed files'][0]);
        $this->assertSame(operational_check::OK, $results['retention'][0]);
    }

    public function test_entries_older_than_their_retention_fail_and_the_task_clears_them(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('airetentiondays', 30, 'mod_sideline');
        set_config('traceretentiondays', 14, 'mod_sideline');
        $course = $this->getDataGenerator()->create_course();
        $sideline = $this->getDataGenerator()->create_module('sideline', ['course' => $course->id]);
        $old = self::NOW - 40 * DAYSECS;
        $DB->insert_record('sideline_aicall', (object) ['sidelineid' => $sideline->id, 'userid' => 2, 'actionid' => 0,
            'timecreated' => $old]);
        $DB->insert_record('sideline_trace', (object) ['sidelineid' => $sideline->id, 'userid' => 2, 'trace' => '{}',
            'timecreated' => $old]);
        $DB->insert_record('sideline_daycount', (object) ['sidelineid' => $sideline->id, 'userid' => 2, 'groupid' => 0,
            'cmid' => 0, 'eventtype' => 'viewed', 'daystart' => group_service::day_start($old)]);

        $results = $this->results();
        $this->assertSame(operational_check::FAIL, $results['ai entries'][0]);
        $this->assertSame(operational_check::FAIL, $results['traces'][0]);
        $this->assertSame(operational_check::FAIL, $results['group day counts'][0]);
        $this->assertStringContainsString('1 older than the retention of 30 days', $results['ai entries'][1]);

        // What the scheduled task does on the same day.
        $DB->delete_records_select('sideline_trace', 'timecreated < ?', [self::NOW - 14 * DAYSECS]);
        \mod_sideline\local\group\day_count::purge(self::NOW);
        \mod_sideline\local\ai\ai_records::forget_older_than(self::NOW - 30 * DAYSECS);
        $results = $this->results();
        $this->assertSame(operational_check::OK, $results['traces'][0]);
        $this->assertSame(operational_check::OK, $results['group day counts'][0]);
        $this->assertSame(operational_check::OK, $results['ai entries'][0], 'The retention removed the record of origin');
    }

    public function test_switched_off_retention_and_tracing_are_reported(): void {
        $this->resetAfterTest();
        set_config('airetentiondays', 0, 'mod_sideline');
        set_config('tracing', 1, 'mod_sideline');
        set_config('tracinguntil', self::NOW + DAYSECS, 'mod_sideline');
        $results = $this->results();
        $this->assertSame(operational_check::WARN, $results['retention'][0]);
        $this->assertSame(operational_check::WARN, $results['traces'][0]);
        $this->assertSame(operational_check::OK, $results['ai entries'][0]);
    }

    public function test_debug_exports_by_people_who_are_no_administrators_are_reported(): void {
        global $DB;
        $this->resetAfterTest();
        $former = $this->getDataGenerator()->create_user();
        $row = ['sidelineid' => 0, 'userid' => 0, 'purpose' => 'support', 'timefrom' => 0, 'timeto' => 0,
            'timecreated' => self::NOW - DAYSECS];
        $DB->insert_record('sideline_debuglog', (object) (['adminid' => get_admin()->id] + $row));
        $this->assertSame(operational_check::OK, $this->results()['debug exports'][0]);
        $DB->insert_record('sideline_debuglog', (object) (['adminid' => $former->id] + $row));
        [$result, $detail] = $this->results()['debug exports'];
        $this->assertSame(operational_check::WARN, $result);
        $this->assertStringContainsString('2 exports in the last 30 days; 1 by people', $detail);
    }

    public function test_the_check_reads_no_content(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $sideline = $this->getDataGenerator()->create_module('sideline', ['course' => $course->id]);
        $DB->insert_record('sideline_trace', (object) ['sidelineid' => $sideline->id, 'userid' => 2,
            'trace' => '{"secret":"Alice Example"}', 'timecreated' => self::NOW]);
        $output = json_encode(operational_check::run(self::NOW));
        $this->assertStringNotContainsString('Alice', $output);
        $this->assertStringNotContainsString('secret', $output);
    }

    public function test_a_removed_file_stops_the_upgrade_and_fails_the_check(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/sideline/db/upgrade.php');
        $this->resetAfterTest();
        $this->assertSame(operational_check::OK, $this->results()['removed files'][0], 'The code base under test is clean');

        // A copy of the plugin directory with the list of removed files, outside the code base.
        $base = make_request_directory();
        check_dir_exists($base . '/db');
        copy($CFG->dirroot . '/mod/sideline/db/removed_files.txt', $base . '/db/removed_files.txt');
        mod_sideline_upgrade_check_removed_files($base);
        $this->assertSame([], operational_check::removed_files_in($base));

        // A release copied over the directory of an older one.
        check_dir_exists($base . '/tests/load');
        file_put_contents($base . '/tests/load/seed_large.php', "<?php\n");
        $this->assertSame(['tests/load/seed_large.php'], operational_check::removed_files_in($base));
        try {
            mod_sideline_upgrade_check_removed_files($base);
            $this->fail('The upgrade must stop');
        } catch (\moodle_exception $e) {
            $this->assertSame('err_removed_files', $e->errorcode);
            $this->assertStringContainsString('tests/load/seed_large.php', $e->getMessage());
            $this->assertStringContainsString('Delete the directory mod/sideline completely', $e->getMessage());
        }

        // Without the list (an old release), nothing is checked and nothing is reported.
        unlink($base . '/db/removed_files.txt');
        $this->assertSame([], operational_check::removed_files_in($base));
        mod_sideline_upgrade_check_removed_files($base);
    }

    public function test_the_upgrade_removes_the_group_snapshots_of_0_4_1_from_file_stores(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/sideline/db/upgrade.php');
        $this->resetAfterTest();
        $dirs = [
            $CFG->cachedir . '/cachestore_file/default_application/mod_sideline_groupsnapshot/1a',
            $CFG->localcachedir . '/cachestore_file/default_application/mod_sideline_groupsnapshot/2b',
        ];
        $other = $CFG->cachedir . '/cachestore_file/default_application/mod_other_groupsnapshot';
        foreach (array_merge($dirs, [$other]) as $dir) {
            check_dir_exists($dir);
            file_put_contents($dir . '/entry.cache', serialize(['userids' => [3, 4, 5]]));
        }
        [$result, $detail] = $this->results()['caches'];
        $this->assertSame(operational_check::FAIL, $result);
        $this->assertStringContainsString('mod_sideline_groupsnapshot', $detail);

        mod_sideline_upgrade_remove_group_snapshots();
        foreach ($dirs as $dir) {
            $this->assertDirectoryDoesNotExist(dirname($dir));
        }
        $this->assertFileExists($other . '/entry.cache', 'Other definitions are not touched');
        $this->assertSame(operational_check::OK, $this->results()['caches'][0]);
        remove_dir($other);
    }
}
