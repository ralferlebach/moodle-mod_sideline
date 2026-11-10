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

namespace mod_sideline\tools;

/**
 * Moodle's AI manager with a provider that takes its time and answers without the network.
 *
 * Everything else is core: the manager picks the configured provider, stores the action in ai_action_register and
 * ai_action_generate_text after the answer, as on a live site. Only the call of the provider is replaced, so that
 * another process can act while the call is in flight (tools/race/run.php, issue #28).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class slow_ai_manager extends \core_ai\manager {
    /** @var int Time every call takes in milliseconds. */
    public int $millis = 1500;
    /** @var string File created when the first call starts, with the prompt in it; empty for none. */
    public string $signal = '';

    #[\Override]
    protected function call_action_provider(
        \core_ai\provider $provider,
        \core_ai\aiactions\base $action
    ): \core_ai\aiactions\responses\response_base {
        $prompt = (string) $action->get_configuration('prompttext');
        if ($this->signal !== '') {
            // Written completely before it appears: another process may read the prompt as soon as the file exists.
            file_put_contents($this->signal . '.part', $prompt);
            rename($this->signal . '.part', $this->signal);
            $this->signal = '';
        }
        usleep($this->millis * 1000);
        $blocks = [];
        if (preg_match('/FACTS:\n(.*?)\n\nOUTPUT FORMAT/s', $prompt, $m)) {
            foreach (json_decode($m[1], true)['blocks'] ?? [] as $block) {
                $blocks[] = ['key' => $block['key'], 'text' => 'The coach says this is about '
                    . implode(' and ', $block['required_tokens'] ?? [])];
            }
        }
        $response = new \core_ai\aiactions\responses\response_generate_text(success: true);
        $response->set_response_data(['id' => 'race-' . random_string(12), 'fingerprint' => 'race', 'model' => 'race',
            'generatedcontent' => $blocks ? json_encode(['blocks' => $blocks]) : 'Answer of the provider',
            'finishreason' => 'stop', 'prompttokens' => 1, 'completiontokens' => 1]);
        return $response;
    }
}
