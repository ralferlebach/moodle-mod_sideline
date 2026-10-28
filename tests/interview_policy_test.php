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

namespace mod_sideline\local\dialog;

/**
 * The site-wide interview policy as upper bound for every activity (P-31, P-15).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_sideline\local\dialog\dialog_manager
 */
#[\PHPUnit\Framework\Attributes\CoversClass(dialog_manager::class)]
final class interview_policy_test extends \advanced_testcase {
    /** @var int Fixed time. */
    private const NOW = 1800000000;

    /** @var \stdClass Course. */
    private \stdClass $course;
    /** @var \stdClass Learner. */
    private \stdClass $student;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
    }

    /**
     * An instance with questions on and no own cooldown.
     *
     * @return \stdClass Instance record.
     */
    private function instance(): \stdClass {
        global $DB;
        $instance = $this->getDataGenerator()->create_module('sideline', [
            'course' => $this->course->id, 'dialogenabled' => 1, 'dialogcooldown' => 0,
        ]);
        return $DB->get_record('sideline', ['id' => $instance->id]);
    }

    /**
     * Create a question.
     *
     * @param \stdClass $sideline Instance.
     * @param string $qtype Type.
     * @return int Question id.
     */
    private function question(\stdClass $sideline, string $qtype = question_type::MC): int {
        $record = ['sidelineid' => $sideline->id, 'qtype' => $qtype, 'prompt' => 'Q ' . $qtype];
        if ($qtype === question_type::TEXT) {
            $record['config'] = ['maxlength' => 200];
        }
        return $this->getDataGenerator()->get_plugin_generator('mod_sideline')->create_question($record);
    }

    /**
     * The id of the question due now, or null.
     *
     * @param \stdClass $sideline Instance.
     * @param int $now Time.
     * @return int|null
     */
    private function due(\stdClass $sideline, int $now = self::NOW): ?int {
        $question = dialog_manager::due_question($sideline, (int) $this->student->id, [], $now);
        return $question ? (int) $question->id : null;
    }

    public function test_site_switch_stops_every_question_but_skipping_stays_possible(): void {
        global $DB;
        $sideline = $this->instance();
        $qid = $this->question($sideline);
        $this->assertSame($qid, $this->due($sideline));

        set_config('interviewsenabled', 0, 'mod_sideline');
        $this->assertFalse(dialog_manager::site_allows_interviews());
        $this->assertNull($this->due($sideline));
        try {
            dialog_manager::answer($sideline, (int) $this->student->id, $qid, 0, false, self::NOW);
            $this->fail('An answer must be rejected while interviews are off');
        } catch (\invalid_parameter_exception $e) {
            $this->assertStringContainsString('site policy', $e->getMessage());
        }
        dialog_manager::answer($sideline, (int) $this->student->id, $qid, null, true, self::NOW);
        $this->assertSame(1, $DB->count_records('sideline_answer', ['skipped' => 1]));
    }

    public function test_answers_lose_their_effect_when_the_policy_no_longer_allows_them(): void {
        $sideline = $this->instance();
        $qid = $this->question($sideline);
        dialog_manager::answer($sideline, (int) $this->student->id, $qid, 1, false, self::NOW);
        $this->assertSame(['focus_deadlines'], dialog_manager::effects($sideline, (int) $this->student->id));

        set_config('interviewtypes', 'slider', 'mod_sideline');
        $this->assertSame([], dialog_manager::effects($sideline, (int) $this->student->id));
        set_config('interviewtypes', 'mc', 'mod_sideline');
        set_config('interviewsenabled', 0, 'mod_sideline');
        $this->assertSame([], dialog_manager::effects($sideline, (int) $this->student->id));
        // The answer itself stays visible to the learner, who can still delete it.
        $this->assertCount(1, dialog_manager::my_answers($sideline, (int) $this->student->id));
    }

    public function test_only_allowed_types_are_asked(): void {
        $sideline = $this->instance();
        $text = $this->question($sideline, question_type::TEXT);
        $mc = $this->question($sideline, question_type::MC);
        $this->assertSame($text, $this->due($sideline));

        set_config('interviewtypes', 'mc,slider,semdiff', 'mod_sideline');
        $this->assertSame(['mc', 'slider', 'semdiff'], dialog_manager::allowed_types());
        $this->assertSame($mc, $this->due($sideline), 'Free text is not asked any more');

        set_config('interviewtypes', '', 'mod_sideline');
        $this->assertSame([], dialog_manager::allowed_types());
        $this->assertNull($this->due($sideline));
    }

    public function test_site_minimum_gap_is_a_lower_bound_for_the_activity_cooldown(): void {
        $sideline = $this->instance();
        $first = $this->question($sideline);
        $second = $this->question($sideline);
        set_config('interviewmingap', 2 * DAYSECS, 'mod_sideline');
        dialog_manager::answer($sideline, (int) $this->student->id, $first, 0, false, self::NOW);

        $this->assertNull($this->due($sideline, self::NOW + DAYSECS), 'The activity says 0, the site says two days');
        $this->assertSame($second, $this->due($sideline, self::NOW + 2 * DAYSECS + 1));
    }

    public function test_weekly_limit_counts_across_all_activities_of_the_site(): void {
        $first = $this->instance();
        $second = $this->instance();
        $q1 = $this->question($first);
        $q2 = $this->question($second);
        set_config('interviewmaxperweek', 1, 'mod_sideline');
        set_config('interviewmingap', 0, 'mod_sideline');
        $this->assertSame($q2, $this->due($second));

        dialog_manager::answer($first, (int) $this->student->id, $q1, null, true, self::NOW);
        $this->assertNull($this->due($second, self::NOW + HOURSECS), 'A skip counts as an interaction too');
        $this->assertSame($q2, $this->due($second, self::NOW + WEEKSECS + 1));

        set_config('interviewmaxperweek', 0, 'mod_sideline');
        $this->assertSame($q2, $this->due($second, self::NOW + HOURSECS));
    }
}
