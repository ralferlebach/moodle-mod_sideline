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

namespace mod_sideline\local\cache;

use mod_sideline\local\ai\ai_gateway;
use mod_sideline\local\service\commentary_service;
use mod_sideline\tests\fake_text_generator;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/fake_text_generator.php');

/**
 * Cache correctness, compare and swap, invalidating events and query budgets (P1-13).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_sideline\local\cache\commentary_cache
 * @covers \mod_sideline\local\observer
 * @covers \mod_sideline\local\service\commentary_service
 */
#[\PHPUnit\Framework\Attributes\CoversClass(commentary_cache::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_sideline\local\observer::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_sideline\local\service\commentary_service::class)]
final class cache_concurrency_test extends \advanced_testcase {
    /** @var \stdClass Course. */
    private \stdClass $course;
    /** @var \stdClass Learner. */
    private \stdClass $student;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('enablecompletion', 1);
        $this->course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
    }

    /**
     * Instance and commentary helper.
     *
     * @param \stdClass $instance Instance.
     * @param fake_text_generator|null $fake AI double.
     * @return array
     */
    private function get(\stdClass $instance, ?fake_text_generator $fake = null): array {
        global $DB;
        $this->setUser($this->student);
        return (new commentary_service(new ai_gateway($fake ?? new fake_text_generator())))->get(
            $DB->get_record('sideline', ['id' => $instance->id]),
            $this->course,
            $this->student,
            \context_module::instance($instance->cmid),
            time()
        );
    }

    public function test_a_late_write_after_an_invalidation_is_dropped(): void {
        global $DB;
        $instance = $this->getDataGenerator()->create_module('sideline', ['course' => $this->course->id]);
        commentary_cache::put((int) $instance->id, (int) $this->student->id, 'old', ['blocks' => []], 0, time());
        [, $stamp] = commentary_cache::lookup((int) $instance->id, (int) $this->student->id, 'new', time());

        // An event arrives while the generation runs.
        commentary_cache::mark_user_dirty((int) $this->course->id, (int) $this->student->id);
        $stored = commentary_cache::put(
            (int) $instance->id,
            (int) $this->student->id,
            'new',
            ['blocks' => [['key' => 'x']]],
            0,
            time(),
            $stamp
        );
        $this->assertFalse($stored);
        $row = $DB->get_record('sideline_cache', ['sidelineid' => $instance->id]);
        $this->assertEquals(1, $row->dirty);
        $this->assertSame('old', $row->fingerprint);

        // A deleted row is not resurrected by a generation that started before the deletion.
        commentary_cache::invalidate_user((int) $instance->id, (int) $this->student->id);
        $this->assertFalse(commentary_cache::put((int) $instance->id, (int) $this->student->id, 'new', [], 0, time(), $stamp + 1));
        $this->assertFalse($DB->record_exists('sideline_cache', ['sidelineid' => $instance->id]));
    }

    public function test_an_event_during_ai_generation_leaves_the_entry_stale(): void {
        global $DB;
        set_config('aienabled', 1, 'mod_sideline');
        $instance = $this->getDataGenerator()->create_module('sideline', ['course' => $this->course->id, 'aienabled' => 1]);
        $this->get($instance);
        $DB->set_field('sideline_cache', 'dirty', 1, ['sidelineid' => $instance->id]);
        $fake = new fake_text_generator();
        $echo = fake_text_generator::echoing_tokens();
        $courseid = (int) $this->course->id;
        $userid = (int) $this->student->id;
        $fake->responder = function (string $prompt) use ($echo, $courseid, $userid) {
            commentary_cache::mark_user_dirty($courseid, $userid);
            return $echo($prompt);
        };
        $result = $this->get($instance, $fake);
        $this->assertSame('ai', $result['commentary']['source'], 'The answer itself is still delivered');
        $this->assertEquals(
            1,
            $DB->get_field('sideline_cache', 'dirty', ['sidelineid' => $instance->id]),
            'but not stored as fresh',
        );
    }

    /**
     * Data provider.
     *
     * @return array
     */
    public static function event_provider(): array {
        return [
            'role unassigned' => ['role'],
            'enrolment updated' => ['enrolment'],
            'group membership' => ['group'],
        ];
    }

    /**
     * Test with data provider.
     *
     * @dataProvider event_provider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('event_provider')]
    public function test_rights_and_membership_events_mark_the_cache_dirty(string $kind): void {
        global $DB;
        $instance = $this->getDataGenerator()->create_module('sideline', ['course' => $this->course->id]);
        $this->get($instance);
        $this->assertEquals(0, $DB->get_field('sideline_cache', 'dirty', ['sidelineid' => $instance->id]));
        switch ($kind) {
            case 'role':
                $role = $DB->get_record('role', ['shortname' => 'student']);
                role_unassign($role->id, $this->student->id, \context_course::instance($this->course->id)->id);
                break;
            case 'enrolment':
                $plugin = enrol_get_plugin('manual');
                $enrol = $DB->get_record('enrol', ['courseid' => $this->course->id, 'enrol' => 'manual']);
                $plugin->update_user_enrol($enrol, $this->student->id, ENROL_USER_SUSPENDED);
                break;
            case 'group':
                $group = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
                $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $this->student->id]);
                break;
        }
        $this->assertEquals(1, $DB->get_field('sideline_cache', 'dirty', ['sidelineid' => $instance->id]));
    }

    public function test_query_budget_does_not_grow_with_the_number_of_activities(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $manual = ['completion' => COMPLETION_TRACKING_MANUAL];
        $small = $gen->create_course(['enablecompletion' => 1]);
        $large = $gen->create_course(['enablecompletion' => 1]);
        $gen->enrol_user($this->student->id, $small->id, 'student');
        $gen->enrol_user($this->student->id, $large->id, 'student');
        foreach (range(1, 3) as $i) {
            $gen->create_module('page', ['course' => $small->id] + $manual);
        }
        foreach (range(1, 30) as $i) {
            $gen->create_module('page', ['course' => $large->id] + $manual);
        }
        $measure = function (\stdClass $course) use ($gen, $DB): array {
            $instance = $gen->create_module('sideline', ['course' => $course->id]);
            $record = $DB->get_record('sideline', ['id' => $instance->id]);
            $context = \context_module::instance($instance->cmid);
            $service = new commentary_service(new ai_gateway(new fake_text_generator()));
            $this->setUser($this->student);
            // Warm the course caches, as a real request would find them.
            get_fast_modinfo($course, $this->student->id);
            $service->get($record, $course, $this->student, $context, time());
            \cache::make('core', 'completion')->purge();
            $before = $DB->perf_get_reads();
            $service->get($record, $course, $this->student, $context, time());
            return [$DB->perf_get_reads() - $before];
        };
        [$smallreads] = $measure($small);
        [$largereads] = $measure($large);
        // Ten times the activities, at most a handful more queries (no query per activity).
        $this->assertLessThanOrEqual($smallreads + 5, $largereads, "small: $smallreads, large: $largereads");
    }
}
