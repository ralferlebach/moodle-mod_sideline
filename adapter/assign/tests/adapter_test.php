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

namespace slcmod_assign;

/**
 * Assignment adapter: dates after overrides and extensions, submission state.
 *
 * @package    slcmod_assign
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \slcmod_assign\adapter
 * @covers \mod_sideline\local\service\state_collector
 */
#[\PHPUnit\Framework\Attributes\CoversClass(adapter::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_sideline\local\service\state_collector::class)]
final class adapter_test extends \advanced_testcase {
    public function test_overrides_extensions_and_submission(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $student = $gen->create_and_enrol($course, 'student');
        $group = $gen->create_group(['courseid' => $course->id]);
        $gen->create_group_member(['groupid' => $group->id, 'userid' => $student->id]);
        $due = time() + 3 * DAYSECS;
        $assign = $gen->create_module('assign', ['course' => $course->id, 'duedate' => $due, 'allowsubmissionsfromdate' => 0,
            'cutoffdate' => 0]);
        $cm = get_fast_modinfo($course, $student->id)->get_cm($assign->cmid);
        $adapter = new adapter();

        $facts = $adapter->collect([$cm->id => $cm], (int) $student->id, time())[$cm->id];
        $this->assertSame($due, $facts->deadline);
        $this->assertFalse($facts->submitted);

        $DB->insert_record('assign_overrides', (object) ['assignid' => $assign->id, 'groupid' => $group->id, 'sortorder' => 1,
            'duedate' => $due + DAYSECS]);
        $this->assertSame($due + DAYSECS, $adapter->collect([$cm->id => $cm], (int) $student->id, time())[$cm->id]->deadline);
        $DB->insert_record(
            'assign_overrides',
            (object) ['assignid' => $assign->id, 'userid' => $student->id, 'duedate' => $due + 2 * DAYSECS],
        );
        $this->assertSame($due + 2 * DAYSECS, $adapter->collect([$cm->id => $cm], (int) $student->id, time())[$cm->id]->deadline);
        $DB->insert_record('assign_user_flags', (object) ['assignment' => $assign->id, 'userid' => $student->id,
            'extensionduedate' => $due + 5 * DAYSECS, 'locked' => 0, 'mailed' => 0, 'workflowstate' => '', 'allocatedmarker' => 0]);
        $this->assertSame($due + 5 * DAYSECS, $adapter->collect([$cm->id => $cm], (int) $student->id, time())[$cm->id]->deadline);

        $DB->insert_record('assign_submission', (object) [
            'assignment' => $assign->id, 'userid' => $student->id, 'status' => 'submitted',
            'latest' => 1, 'attemptnumber' => 0, 'groupid' => 0, 'timecreated' => time(), 'timemodified' => time()]);
        $this->assertTrue($adapter->collect([$cm->id => $cm], (int) $student->id, time())[$cm->id]->submitted);
    }

    public function test_query_count_does_not_grow_with_the_number_of_assignments(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $student = $gen->create_and_enrol($course, 'student');
        $cms = [];
        foreach (range(1, 8) as $i) {
            $a = $gen->create_module('assign', ['course' => $course->id]);
            $cms[$a->cmid] = get_fast_modinfo($course, $student->id)->get_cm($a->cmid);
        }
        $adapter = new adapter();
        $before = $DB->perf_get_reads();
        $adapter->collect(array_slice($cms, 0, 2, true), (int) $student->id, time());
        $two = $DB->perf_get_reads() - $before;
        $before = $DB->perf_get_reads();
        $adapter->collect($cms, (int) $student->id, time());
        $eight = $DB->perf_get_reads() - $before;
        $this->assertLessThanOrEqual($two, $eight);
    }
}
