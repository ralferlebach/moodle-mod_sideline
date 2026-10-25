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

use mod_sideline\local\ai\ai_gateway;
use mod_sideline\local\consent\consent_manager;
use mod_sideline\tests\fake_text_generator;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/fake_text_generator.php');

/**
 * Integration tests: course, activities, completion, cache, AI and consent together.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_sideline\local\service\commentary_service::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_sideline\local\cache\commentary_cache::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_sideline\local\consent\consent_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_sideline\local\observer::class)]
final class commentary_service_test extends \advanced_testcase {
    /** @var \stdClass Course. */
    private \stdClass $course;
    /** @var \stdClass Learner. */
    private \stdClass $student;
    /** @var \stdClass First observed activity. */
    private \stdClass $page1;
    /** @var \stdClass Second observed activity. */
    private \stdClass $page2;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('enablecompletion', 1);
        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course(['enablecompletion' => 1, 'fullname' => 'Secret Course Name']);
        $this->student = $gen->create_and_enrol($this->course, 'student', [
            'firstname' => 'Maria', 'lastname' => 'Schmidt', 'email' => 'maria.schmidt@example.com',
        ]);
        $manual = ['completion' => COMPLETION_TRACKING_MANUAL];
        $this->page1 = $gen->create_module('page', ['course' => $this->course->id, 'name' => 'Alpha unit'] + $manual);
        $this->page2 = $gen->create_module('page', ['course' => $this->course->id, 'name' => 'Beta unit'] + $manual);
    }

    /**
     * Create a Sideline instance and return it with its context.
     *
     * @param array $options Instance options.
     * @return array The instance record, the cm and the context.
     */
    private function sideline(array $options = []): array {
        $instance = $this->getDataGenerator()->create_module('sideline', ['course' => $this->course->id] + $options);
        $cm = get_coursemodule_from_instance('sideline', $instance->id);
        return [$instance, $cm, \context_module::instance($cm->id)];
    }

    /**
     * Get the commentary through the service.
     *
     * @param \stdClass $instance Instance.
     * @param \context_module $context Context.
     * @param fake_text_generator|null $fake Fake generator, when AI should be possible.
     * @param int|null $now Time.
     * @return array
     */
    private function get(
        \stdClass $instance,
        \context_module $context,
        ?fake_text_generator $fake = null,
        ?int $now = null,
    ): array {
        global $DB;
        $this->setUser($this->student);
        $record = $DB->get_record('sideline', ['id' => $instance->id], '*', MUST_EXIST);
        $service = new commentary_service($fake ? new ai_gateway($fake) : new ai_gateway(new fake_text_generator()));
        return $service->get($record, $this->course, $this->student, $context, $now ?? time());
    }

    /**
     * Mark an activity complete for the student.
     *
     * @param \stdClass $page Page module record.
     */
    private function complete(\stdClass $page): void {
        $completion = new \completion_info($this->course);
        $cm = get_coursemodule_from_instance('page', $page->id);
        $completion->update_state($cm, COMPLETION_COMPLETE, $this->student->id);
    }

    public function test_commentary_is_template_text_with_a_link_to_the_next_activity(): void {
        [$instance, , $context] = $this->sideline();
        $this->complete($this->page1);
        $result = $this->get($instance, $context);

        $this->assertSame('ok', $result['status']);
        $this->assertSame('template', $result['commentary']['source']);
        $keys = array_column($result['commentary']['blocks'], 'key');
        $this->assertContains('success_next', $keys);
        $first = $result['commentary']['blocks'][0];
        $this->assertStringContainsString('Alpha unit', $first['text']);
        $this->assertStringContainsString('Beta unit', $first['text']);
        $this->assertStringContainsString('/mod/page/view.php', $first['action']['url']);
    }

    public function test_hidden_activities_are_never_mentioned(): void {
        global $DB;
        $hidden = $this->getDataGenerator()->create_module('page', [
            'course' => $this->course->id, 'name' => 'Hidden gem', 'visible' => 0,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        [$instance, , $context] = $this->sideline();
        $this->complete($this->page1);
        $this->complete($this->page2);
        $json = json_encode($this->get($instance, $context)['commentary']);
        $this->assertStringNotContainsString('Hidden gem', $json);
        $this->assertSame(1, $DB->count_records('page', ['id' => $hidden->id]));
    }

    public function test_second_call_is_served_from_the_cache(): void {
        global $DB;
        [$instance, , $context] = $this->sideline();
        $this->get($instance, $context);
        $first = $DB->get_record('sideline_cache', ['sidelineid' => $instance->id, 'userid' => $this->student->id]);
        $this->get($instance, $context);
        $second = $DB->get_record('sideline_cache', ['sidelineid' => $instance->id, 'userid' => $this->student->id]);
        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->timemodified, $second->timemodified);
        $this->assertSame(1, $DB->count_records('sideline_cache'));
    }

    public function test_completion_event_marks_the_cache_dirty_and_the_text_changes(): void {
        global $DB;
        [$instance, , $context] = $this->sideline();
        $before = $this->get($instance, $context)['commentary'];
        $this->complete($this->page1);
        $this->assertSame(1, (int) $DB->get_field('sideline_cache', 'dirty', ['sidelineid' => $instance->id]));
        $after = $this->get($instance, $context)['commentary'];
        $this->assertNotSame($before['blocks'][0]['key'], $after['blocks'][0]['key']);
    }

    public function test_time_transition_invalidates_without_any_event(): void {
        global $DB;
        $soon = time() + 2 * DAYSECS;
        $DB->set_field('course_modules', 'completionexpected', $soon, ['id' => $this->page1->cmid]);
        rebuild_course_cache($this->course->id, true);
        [$instance, , $context] = $this->sideline();

        $now = time();
        $before = $this->get($instance, $context, null, $now)['commentary'];
        $this->assertSame('deadline_soon', $before['blocks'][0]['key']);
        // After the deadline has passed, with no event in between, the text must change.
        $later = $this->get($instance, $context, null, $soon + 60)['commentary'];
        $this->assertSame('deadline_passed', $later['blocks'][0]['key']);
    }

    public function test_commentary_is_never_shared_between_users(): void {
        global $DB;
        [$instance, , $context] = $this->sideline();
        $other = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->get($instance, $context);
        $this->assertSame(0, $DB->count_records('sideline_cache', ['userid' => $other->id]));
    }

    public function test_ai_text_is_used_when_valid_and_real_values_are_resolved_locally(): void {
        set_config('aienabled', 1, 'mod_sideline');
        [$instance, , $context] = $this->sideline(['aienabled' => 1]);
        $this->complete($this->page1);
        $fake = new fake_text_generator();
        $fake->responder = fake_text_generator::echoing_tokens();

        $commentary = $this->get($instance, $context, $fake)['commentary'];
        $this->assertSame(1, $fake->calls);
        $this->assertSame('ai', $commentary['source']);
        $this->assertStringContainsString('Alpha unit', $commentary['blocks'][0]['text']);
        // The action link still comes from the server, not from the model.
        $this->assertStringContainsString('/mod/page/view.php', $commentary['blocks'][0]['action']['url']);
    }

    public function test_nothing_identifying_leaves_the_site(): void {
        set_config('aienabled', 1, 'mod_sideline');
        [$instance, , $context] = $this->sideline(['aienabled' => 1]);
        $this->complete($this->page1);
        $fake = new fake_text_generator();
        $fake->responder = fake_text_generator::echoing_tokens();
        $this->get($instance, $context, $fake);

        $prompt = implode("\n", $fake->prompts);
        foreach (
            ['Maria', 'Schmidt', 'maria.schmidt@example.com', 'Alpha unit', 'Beta unit', 'Secret Course Name',
            'http', (string) $this->student->id . '"'] as $forbidden
        ) {
            $this->assertStringNotContainsString($forbidden, $prompt, "Prompt leaks: $forbidden");
        }
    }

    public function test_invalid_ai_output_falls_back_to_templates(): void {
        set_config('aienabled', 1, 'mod_sideline');
        [$instance, , $context] = $this->sideline(['aienabled' => 1]);
        $this->complete($this->page1);
        $fake = new fake_text_generator();
        // An invented number must be rejected.
        $fake->responder = fake_text_generator::echoing_tokens(' with 99 points');
        $commentary = $this->get($instance, $context, $fake)['commentary'];
        $this->assertSame(1, $fake->calls);
        $this->assertSame('template', $commentary['source']);
    }

    public function test_provider_failure_falls_back_to_templates(): void {
        set_config('aienabled', 1, 'mod_sideline');
        [$instance, , $context] = $this->sideline(['aienabled' => 1]);
        $fake = new fake_text_generator();
        $fake->responder = static fn(string $prompt) => null;
        $this->assertSame('template', $this->get($instance, $context, $fake)['commentary']['source']);
    }

    public function test_no_ai_call_when_the_site_switch_is_off(): void {
        set_config('aienabled', 0, 'mod_sideline');
        [$instance, , $context] = $this->sideline(['aienabled' => 1]);
        $fake = new fake_text_generator();
        $fake->responder = fake_text_generator::echoing_tokens();
        $this->get($instance, $context, $fake);
        $this->assertSame(0, $fake->calls);
    }

    public function test_no_ai_call_when_the_activity_switch_is_off(): void {
        set_config('aienabled', 1, 'mod_sideline');
        [$instance, , $context] = $this->sideline(['aienabled' => 0]);
        $fake = new fake_text_generator();
        $this->get($instance, $context, $fake);
        $this->assertSame(0, $fake->calls);
    }

    public function test_no_ai_call_when_the_moodle_ai_policy_is_not_accepted(): void {
        set_config('aienabled', 1, 'mod_sideline');
        [$instance, , $context] = $this->sideline(['aienabled' => 1]);
        $fake = new fake_text_generator();
        $fake->available = false;
        $this->assertSame('template', $this->get($instance, $context, $fake)['commentary']['source']);
        $this->assertSame(0, $fake->calls);
    }

    public function test_cost_cap_stops_ai_calls(): void {
        global $DB;
        set_config('aienabled', 1, 'mod_sideline');
        set_config('aihourlycap', 1, 'mod_sideline');
        [$instance, , $context] = $this->sideline(['aienabled' => 1]);
        $DB->insert_record('sideline_cache', (object) [
            'sidelineid' => $instance->id, 'userid' => get_admin()->id, 'fingerprint' => 'x', 'source' => 'ai',
            'commentary' => '{}', 'dirty' => 0, 'nexttransition' => 0, 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $fake = new fake_text_generator();
        $fake->responder = fake_text_generator::echoing_tokens();
        $this->get($instance, $context, $fake);
        $this->assertSame(0, $fake->calls);
    }

    public function test_consent_gate_blocks_every_personal_step_until_accepted(): void {
        global $DB;
        set_config('aienabled', 1, 'mod_sideline');
        [$instance, , $context] = $this->sideline(['aienabled' => 1, 'consentrequired' => 1]);
        $fake = new fake_text_generator();
        $fake->responder = fake_text_generator::echoing_tokens();

        $result = $this->get($instance, $context, $fake);
        $this->assertSame('consent_required', $result['status']);
        $this->assertNull($result['commentary']);
        $this->assertSame(0, $fake->calls);
        $this->assertSame(0, $DB->count_records('sideline_cache'));

        $record = $DB->get_record('sideline', ['id' => $instance->id]);
        consent_manager::record_decision($record, (int) $this->student->id, false, $context->id, time());
        $this->assertSame(consent_manager::DECLINED, consent_manager::status($record, (int) $this->student->id));
        $this->assertSame('consent_required', $this->get($instance, $context, $fake)['status']);
        $this->assertSame(0, $fake->calls);

        consent_manager::record_decision($record, (int) $this->student->id, true, $context->id, time());
        $this->assertSame('ok', $this->get($instance, $context, $fake)['status']);
        $this->assertSame(1, $fake->calls);
    }

    public function test_withdrawal_deletes_generated_commentary_and_stops_processing(): void {
        global $DB;
        [$instance, , $context] = $this->sideline(['consentrequired' => 1]);
        $record = $DB->get_record('sideline', ['id' => $instance->id]);
        consent_manager::record_decision($record, (int) $this->student->id, true, $context->id, time());
        $this->get($instance, $context);
        $this->assertSame(1, $DB->count_records('sideline_cache'));

        $status = consent_manager::record_decision($record, (int) $this->student->id, false, $context->id, time());
        $this->assertSame(consent_manager::WITHDRAWN, $status);
        $this->assertSame(0, $DB->count_records('sideline_cache'));
        $this->assertSame('consent_required', $this->get($instance, $context)['status']);
        // Every decision is logged with version and context.
        $this->assertSame(2, $DB->count_records('sideline_consent_log', ['userid' => $this->student->id]));
    }

    public function test_changed_information_requires_a_new_consent(): void {
        global $DB;
        [$instance, , $context] = $this->sideline(['consentrequired' => 1]);
        $record = $DB->get_record('sideline', ['id' => $instance->id]);
        consent_manager::record_decision($record, (int) $this->student->id, true, $context->id, time());
        $DB->set_field('sideline', 'consentversion', 2, ['id' => $instance->id]);
        $record = $DB->get_record('sideline', ['id' => $instance->id]);
        $this->assertSame(consent_manager::RECONSENT_REQUIRED, consent_manager::status($record, (int) $this->student->id));
        $this->assertFalse(consent_manager::allows_processing($record, (int) $this->student->id));
    }

    public function test_changing_the_persona_changes_the_fingerprint_and_the_text(): void {
        global $DB;
        [$instance, , $context] = $this->sideline(['persona' => 'mentor']);
        $mentor = $this->get($instance, $context)['commentary'];
        $DB->set_field('sideline', 'persona', 'cornercoach', ['id' => $instance->id]);
        $DB->set_field('sideline', 'configversion', 2, ['id' => $instance->id]);
        $coach = $this->get($instance, $context)['commentary'];
        $this->assertSame('cornercoach', $coach['persona']);
        $this->assertNotSame($mentor['blocks'][0]['text'], $coach['blocks'][0]['text']);
    }

    public function test_deleting_the_instance_removes_cache_consent_and_scope(): void {
        global $DB;
        [$instance, $cm, $context] = $this->sideline(['consentrequired' => 1]);
        $record = $DB->get_record('sideline', ['id' => $instance->id]);
        consent_manager::record_decision($record, (int) $this->student->id, true, $context->id, time());
        $this->get($instance, $context);
        \core_courseformat\formatactions::cm($this->course->id)->delete($cm->id);
        foreach (['sideline', 'sideline_cache', 'sideline_consent', 'sideline_consent_log', 'sideline_scope'] as $table) {
            $this->assertSame(0, $DB->count_records($table), $table);
        }
    }
}
