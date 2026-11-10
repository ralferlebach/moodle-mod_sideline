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
 * A configured provider of Moodle's AI subsystem with a mocked HTTP endpoint, for tests of the real path.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_sideline\tests;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * The OpenAI provider of core, configured on Moodle 4.5 and on 5.0 and later, answering through a mocked
 * HTTP client. The fake model answers a generation with the required tokens of each block and a translation
 * with the tokens of each text.
 *
 * Use in an \advanced_testcase with a property array $history.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait core_ai_provider {
    /**
     * Configure and enable the provider for text generation.
     */
    protected function enable_core_ai_provider(): void {
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
    }

    /**
     * Mock the provider endpoint.
     *
     * @param int $status HTTP status.
     * @param int $requests Number of responses to queue.
     * @param callable|null $during Called with the prompt while the provider is answering.
     */
    protected function mock_provider(int $status = 200, int $requests = 1, ?callable $during = null): void {
        ['mock' => $mock] = $this->get_mocked_http_client($this->history);
        for ($i = 0; $i < $requests; $i++) {
            $mock->append($this->responder($status, $during));
        }
    }

    /**
     * One mocked provider answer: generation in English, translation into French.
     *
     * @param int $status HTTP status.
     * @param callable|null $during Called with the prompt while the provider is answering.
     * @return callable
     */
    protected function responder(int $status, ?callable $during = null): callable {
        return function (RequestInterface $request) use ($status, $during) {
            if ($status !== 200) {
                return new Response($status, ['Content-Type' => 'application/json'], '{"error":{"message":"down"}}');
            }
            $body = json_decode((string) $request->getBody(), true);
            $prompt = end($body['messages'])['content'];
            if ($during) {
                $during($prompt);
            }
            $blocks = [];
            if (preg_match('/TEXTS:\n(.*?)\n\nOUTPUT FORMAT/s', $prompt, $m)) {
                // The translation step: keep every token, French wording.
                foreach (json_decode($m[1], true)['blocks'] as $block) {
                    preg_match_all('/\[anon[a-z]*\d*\]/', $block['text'], $tokens);
                    $blocks[] = ['key' => $block['key'], 'text' => 'Très bien, tu avances avec ' . implode(' et ', $tokens[0])];
                }
                $content = json_encode(['blocks' => $blocks]);
            } else if (preg_match('/FACTS:\n(.*?)\n\nOUTPUT FORMAT/s', $prompt, $m)) {
                foreach (json_decode($m[1], true)['blocks'] as $block) {
                    $blocks[] = ['key' => $block['key'], 'text' => 'Great going ' . implode(' and ', $block['required_tokens'])];
                }
                $content = json_encode(['blocks' => $blocks]);
            } else {
                // Not a prompt of this plugin: another plugin's call.
                $content = 'Answer of the provider';
            }
            return new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'id' => 'chatcmpl-' . random_string(12),
                'object' => 'chat.completion', 'created' => 1, 'model' => 'gpt-4o', 'system_fingerprint' => 'f',
                'choices' => [[
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => $content],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            ]));
        };
    }
}
