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

namespace mod_sideline\local\ai;

use GuzzleHttp\Psr7\Response;
use mod_sideline\local\service\commentary_service;
use Psr\Http\Message\RequestInterface;

/**
 * The real path through Moodle's AI subsystem with a configured provider and a mocked HTTP endpoint
 * (P0-03): policy, capability, what leaves the site, what core stores, fallback on errors.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_sideline\local\ai\core_text_generator
 * @covers \mod_sideline\local\ai\ai_gateway
 */
#[\PHPUnit\Framework\Attributes\CoversClass(core_text_generator::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(ai_gateway::class)]
final class ai_integration_test extends \advanced_testcase {
    /** @var \stdClass Course. */
    private \stdClass $course;
    /** @var \stdClass Learner. */
    private \stdClass $student;
    /** @var \stdClass Instance. */
    private \stdClass $instance;
    /** @var array HTTP history. */
    private array $history = [];

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('enablecompletion', 1);
        set_config('aienabled', 1, 'mod_sideline');
        $manager = \core\di::get(\core_ai\manager::class);
        if (method_exists($manager, 'create_provider_instance')) {
            // Moodle 5.0 and later: provider instances.
            $manager->create_provider_instance(
                classname: '\aiprovider_openai\provider',
                name: 'test',
                enabled: true,
                config: ['apikey' => '123', 'enableuserratelimit' => false, 'enableglobalratelimit' => false],
                actionconfig: [\core_ai\aiactions\generate_text::class => ['enabled' => true, 'settings' => [
                    'model' => 'gpt-4o', 'endpoint' => 'https://api.openai.com/v1/chat/completions', 'systeminstruction' => '',
                ]]],
            );
        } else {
            // Moodle 4.5: one configuration per provider plugin.
            set_config('apikey', '123', 'aiprovider_openai');
            set_config('enableuserratelimit', 0, 'aiprovider_openai');
            set_config('enableglobalratelimit', 0, 'aiprovider_openai');
            set_config('action_generate_text_model', 'gpt-4o', 'aiprovider_openai');
            set_config('action_generate_text_endpoint', 'https://api.openai.com/v1/chat/completions', 'aiprovider_openai');
            \core\plugininfo\aiprovider::enable_plugin('openai', 1);
            \core_ai\manager::set_action_state('aiprovider_openai', 'generate_text', 1);
        }
        $this->course = $this->getDataGenerator()->create_course(['enablecompletion' => 1, 'fullname' => 'Secret course']);
        $this->student = $this->getDataGenerator()->create_and_enrol(
            $this->course,
            'student',
            ['firstname' => 'Maria', 'lastname' => 'Schmidt', 'email' => 'maria@example.com']
        );
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id, 'name' => 'Alpha unit',
            'completion' => COMPLETION_TRACKING_MANUAL]);
        $this->getDataGenerator()->create_module('page', ['course' => $this->course->id, 'name' => 'Beta unit',
            'completion' => COMPLETION_TRACKING_MANUAL]);
        $this->setAdminUser();
        (new \completion_info($this->course))->update_state(
            get_coursemodule_from_instance('page', $page->id),
            COMPLETION_COMPLETE,
            $this->student->id
        );
        $this->instance = $this->getDataGenerator()->create_module('sideline', ['course' => $this->course->id, 'aienabled' => 1]);
    }

    /**
     * Mock the provider endpoint. The fake model answers with the required tokens of the prompt.
     *
     * @param int $status HTTP status.
     */
    private function mock_provider(int $status = 200): void {
        ['mock' => $mock] = $this->get_mocked_http_client($this->history);
        $mock->append(function (RequestInterface $request) use ($status) {
            if ($status !== 200) {
                return new Response($status, ['Content-Type' => 'application/json'], '{"error":{"message":"down"}}');
            }
            $body = json_decode((string) $request->getBody(), true);
            $prompt = end($body['messages'])['content'];
            preg_match('/FACTS:\n(.*?)\n\nOUTPUT FORMAT/s', $prompt, $m);
            $blocks = [];
            foreach (json_decode($m[1], true)['blocks'] as $block) {
                $blocks[] = ['key' => $block['key'], 'text' => 'Great going ' . implode(' and ', $block['required_tokens'])];
            }
            $content = json_encode(['blocks' => $blocks]);
            return new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'id' => 'x', 'object' => 'chat.completion', 'created' => 1, 'model' => 'gpt-4o', 'system_fingerprint' => 'f',
                'choices' => [[
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => $content],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            ]));
        });
    }

    /**
     * Commentary through the real core generator.
     *
     * @return array
     */
    private function get(): array {
        global $DB;
        $this->setUser($this->student);
        return (new commentary_service())->get(
            $DB->get_record('sideline', ['id' => $this->instance->id]),
            $this->course,
            $this->student,
            \context_module::instance($this->instance->cmid),
            time()
        );
    }

    public function test_without_accepted_ai_policy_no_request_leaves_the_site(): void {
        $this->mock_provider();
        $result = $this->get();
        $this->assertSame('template', $result['commentary']['source']);
        $this->assertCount(0, $this->history);
    }

    public function test_full_path_sends_only_pseudonymised_facts_and_core_stores_only_tokens(): void {
        global $DB;
        \core_ai\manager::user_policy_accepted((int) $this->student->id, \context_module::instance($this->instance->cmid)->id);
        $this->mock_provider();
        $result = $this->get();

        $this->assertCount(1, $this->history, 'Exactly one request to the provider');
        $sent = (string) $this->history[0]['request']->getBody();
        foreach (['Maria', 'Schmidt', 'maria@example.com', 'Alpha unit', 'Beta unit', 'Secret course', 'view.php'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $sent, "Leaves the site: $forbidden");
        }
        $this->assertSame('ai', $result['commentary']['source']);
        $this->assertStringContainsString('Alpha unit', json_encode($result['commentary']), 'Resolved locally after validation');

        // What Moodle core records about the action holds tokens only.
        $stored = json_encode($DB->get_records('ai_action_generate_text'));
        $this->assertStringNotContainsString('Maria', $stored);
        $this->assertStringNotContainsString('Alpha unit', $stored);
    }

    public function test_provider_errors_and_missing_capability_fall_back_to_templates(): void {
        global $DB;
        \core_ai\manager::user_policy_accepted((int) $this->student->id, \context_module::instance($this->instance->cmid)->id);
        $this->mock_provider(500);
        $this->assertSame('template', $this->get()['commentary']['source']);

        $DB->delete_records('sideline_cache');
        $role = $DB->get_record('role', ['shortname' => 'student']);
        assign_capability('mod/sideline:useai', CAP_PROHIBIT, $role->id, \context_module::instance($this->instance->cmid)->id);
        $this->history = [];
        $this->mock_provider();
        $this->assertSame('template', $this->get()['commentary']['source']);
        $this->assertCount(0, $this->history);
    }
}
