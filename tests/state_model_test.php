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

namespace mod_sideline\local\service;

use mod_sideline\local\model\activity_state;

/**
 * Full state model: availability trees, lock reasons, usage, course completion, time zones (P1-04).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(availability_analyser::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(state_collector::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(rule_evaluator::class)]
final class state_model_test extends \advanced_testcase {
    /** @var int Fixed time. */
    private const NOW = 1800000000;

    /**
     * Date condition.
     *
     * @param string $d Direction.
     * @param int $t Time.
     * @return array
     */
    private static function date(string $d, int $t): array {
        return ['type' => 'date', 'd' => $d, 't' => $t];
    }

    /**
     * Data provider.
     *
     * @return array
     */
    public static function tree_provider(): array {
        $later = self::NOW + DAYSECS;
        $evenlater = self::NOW + 2 * DAYSECS;
        $completion = ['type' => 'completion', 'cm' => 5, 'e' => 1];
        return [
            'flat and' => [['op' => '&', 'c' => [self::date('>=', $later)]], $later],
            'and takes the latest' => [['op' => '&', 'c' => [self::date('>=', $later), self::date('>=', $evenlater)]], $evenlater],
            'or takes the earliest' => [['op' => '|', 'c' => [self::date('>=', $later), self::date('>=', $evenlater)]], $later],
            'or with a non-date child is unknown' => [['op' => '|', 'c' => [self::date('>=', $later), $completion]], null],
            'and with a non-date child keeps the date' => [['op' => '&', 'c' => [self::date('>=', $later), $completion]], $later],
            'negated before is from' => [['op' => '!&', 'c' => [self::date('<', $later)]], $later],
            'negated from is no lower bound' => [['op' => '!&', 'c' => [self::date('>=', $later)]], null],
            'nested' => [['op' => '&', 'c' => [['op' => '|', 'c' => [self::date('>=', $later), self::date('>=', $evenlater)]],
                $completion]], $later],
            'past date' => [['op' => '&', 'c' => [self::date('>=', self::NOW - 10)]], null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tree_provider')]
    public function test_earliest_opening_follows_tree_semantics(array $tree, ?int $expected): void {
        $this->assertSame($expected, (new availability_analyser())->earliest_opening(json_encode($tree), self::NOW));
    }

    public function test_lock_reason_names_only_the_kind_of_condition(): void {
        $a = new availability_analyser();
        $this->assertSame(activity_state::LOCK_COMPLETION, $a->lock_reason(json_encode(['op' => '&', 'c' => [
            ['type' => 'completion', 'cm' => 1, 'e' => 1], ['type' => 'completion', 'cm' => 2, 'e' => 1]]])));
        $this->assertSame(
            activity_state::LOCK_GROUP,
            $a->lock_reason(json_encode(['op' => '&', 'c' => [['type' => 'group', 'id' => 3]]])),
        );
        $this->assertSame(activity_state::LOCK_OTHER, $a->lock_reason(json_encode(['op' => '&', 'c' => [
            ['type' => 'group', 'id' => 3], ['type' => 'grade', 'id' => 1]]])));
    }

    public function test_usage_completion_grade_and_course_completion_are_separate_dimensions(): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/completion/criteria/completion_criteria_activity.php');
        $this->resetAfterTest();
        set_config('enablecompletion', 1);
        $this->preventResetByRollback();
        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        set_config('buffersize', 0, 'logstore_standard');
        get_log_manager(true);
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['enablecompletion' => 1]);
        $student = $gen->create_and_enrol($course, 'student');
        $page = $gen->create_module(
            'page',
            ['course' => $course->id, 'name' => 'Viewed only', 'completion' => COMPLETION_TRACKING_MANUAL],
        );
        $cm = get_fast_modinfo($course, $student->id)->get_cm($page->cmid);

        // A view is usage, not completion.
        $this->setUser($student);
        $event = \mod_page\event\course_module_viewed::create([
            'objectid' => $page->id,
            'context' => \context_module::instance($page->cmid),
        ]);
        $event->trigger();
        $collector = new state_collector();
        $states = $collector->collect($course, (int) $student->id, [$cm->id => $cm]);
        $state = $states[$cm->id];
        $this->assertNotNull($state->lastused);
        $this->assertSame(activity_state::NO, $state->completion);
        $this->assertNull($state->grade);
        $this->assertSame(activity_state::UNKNOWN, $collector->coursecompletion[$course->id], 'No criteria: unknown, not "no"');
    }

    public function test_dates_are_shown_in_the_learner_time_zone_across_dst(): void {
        $this->resetAfterTest();
        global $CFG;
        // Sunday 25 October 2026, 01:30 UTC: one hour before the European change back to standard time.
        $deadline = 1792891800;
        $user = $this->getDataGenerator()->create_user(['timezone' => 'Europe/Berlin', 'lang' => 'en']);
        $this->setUser($user);
        $rec = new \mod_sideline\local\model\recommendation(
            'deadline_soon',
            1,
            1,
            'none',
            null,
            ['label' => 'Essay', 'deadline' => $deadline]
        );
        $text = (new message_composer())->compose([$rec], [], 'mentor', 'en')['blocks'][0]['text'];
        // 01:30 UTC is 02:30 CET (after the switch at 01:00 UTC), not 03:30 CEST.
        $this->assertStringContainsString(userdate($deadline, get_string('strftimedatetimeshort', 'langconfig')), $text);
        $this->assertSame('02:30', userdate($deadline, '%H:%M'));
    }

    public function test_attempts_and_submission_rules(): void {
        $base = ['label' => 'Quiz', 'visible' => true, 'available' => activity_state::YES, 'completion' => activity_state::YES,
            'pass' => activity_state::NO];
        $keys = static fn(array $fields): array => array_map(
            static fn($r) => $r->key,
            (new rule_evaluator())->evaluate([1 => new activity_state(...(['cmid' => 1] + $fields + $base))], self::NOW)
        );
        $this->assertContains('not_passed_retry', $keys(['attemptsused' => 1, 'attemptsallowed' => 3]));
        $this->assertContains('not_passed_final', $keys(['attemptsused' => 3, 'attemptsallowed' => 3]));
        $this->assertContains('not_passed', $keys([]));
        $this->assertContains('submitted_waiting', $keys(['completion' => activity_state::NO, 'pass' => activity_state::UNKNOWN,
            'submitted' => activity_state::YES]));
        $this->assertContains('opens_later', $keys(['completion' => activity_state::NO, 'pass' => activity_state::UNKNOWN,
            'opensat' => self::NOW + DAYSECS]));
    }
}
