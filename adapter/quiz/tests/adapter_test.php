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

namespace slcmod_quiz;

/**
 * Quiz adapter: times and attempts after user and group overrides.
 *
 * @package    slcmod_quiz
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \slcmod_quiz\adapter
 */
#[\PHPUnit\Framework\Attributes\CoversClass(adapter::class)]
final class adapter_test extends \advanced_testcase {
    public function test_attempts_and_overrides(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $student = $gen->create_and_enrol($course, 'student');
        $g1 = $gen->create_group(['courseid' => $course->id]);
        $g2 = $gen->create_group(['courseid' => $course->id]);
        $gen->create_group_member(['groupid' => $g1->id, 'userid' => $student->id]);
        $gen->create_group_member(['groupid' => $g2->id, 'userid' => $student->id]);
        $close = time() + DAYSECS;
        $quiz = $gen->create_module('quiz', ['course' => $course->id, 'attempts' => 1, 'timeclose' => $close]);
        $cm = get_fast_modinfo($course, $student->id)->get_cm($quiz->cmid);
        $adapter = new adapter();

        $facts = $adapter->collect([$cm->id => $cm], (int) $student->id, time())[$cm->id];
        $this->assertSame(0, $facts->attemptsused);
        $this->assertSame(1, $facts->attemptsallowed);
        $this->assertSame($close, $facts->deadline);

        // Group overrides: the most lenient one counts.
        $DB->insert_record(
            'quiz_overrides',
            (object) ['quiz' => $quiz->id, 'groupid' => $g1->id, 'attempts' => 2, 'timeclose' => $close + 10],
        );
        $DB->insert_record(
            'quiz_overrides',
            (object) ['quiz' => $quiz->id, 'groupid' => $g2->id, 'attempts' => 3, 'timeclose' => $close + 20],
        );
        $facts = $adapter->collect([$cm->id => $cm], (int) $student->id, time())[$cm->id];
        $this->assertSame(3, $facts->attemptsallowed);
        $this->assertSame($close + 20, $facts->deadline);

        // A user override wins.
        $DB->insert_record('quiz_overrides', (object) ['quiz' => $quiz->id, 'userid' => $student->id, 'attempts' => 0]);
        $this->assertSame(0, $adapter->collect([$cm->id => $cm], (int) $student->id, time())[$cm->id]->attemptsallowed);

        $DB->insert_record('quiz_attempts', (object) [
            'quiz' => $quiz->id, 'userid' => $student->id, 'attempt' => 1, 'uniqueid' => 9999,
            'layout' => '',
            'preview' => 0,
            'state' => 'finished',
            'timestart' => time(),
            'timefinish' => time(),
            'timemodified' => time(),
            'sumgrades' => 0]);
        $this->assertSame(1, $adapter->collect([$cm->id => $cm], (int) $student->id, time())[$cm->id]->attemptsused);
    }
}
