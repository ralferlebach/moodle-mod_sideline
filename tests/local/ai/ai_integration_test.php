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

use mod_sideline\local\service\commentary_service;
use mod_sideline\tests\core_ai_provider;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/sideline/tests/fixtures/core_ai_provider.php');

#[\PHPUnit\Framework\Attributes\CoversClass(core_text_generator::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(ai_gateway::class)]
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
final class ai_integration_test extends \advanced_testcase {
    use core_ai_provider;

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
        $this->enable_core_ai_provider();
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
     * Commentary through the real core generator.
     *
     * @param string|null $lang Language to force for the learner.
     * @return array
     */
    private function get(?string $lang = null): array {
        global $DB;
        $this->setUser($this->student);
        if ($lang !== null) {
            // A minimal language pack: Moodle only switches to installed languages.
            global $CFG;
            check_dir_exists($CFG->dataroot . '/lang/' . $lang);
            $file = $CFG->dataroot . '/lang/' . $lang . '/langconfig.php';
            file_put_contents($file, "<?php\n\$string['thislanguage'] = '$lang';\n");
            get_string_manager()->reset_caches(true);
            force_current_language($lang);
        }
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

    public function test_translation_step_through_core_keeps_tokens_and_core_stores_only_tokens(): void {
        global $DB;
        \core_ai\manager::user_policy_accepted((int) $this->student->id, \context_module::instance($this->instance->cmid)->id);
        $this->mock_provider(200, 2);
        // A French learner whose provider answers in English: the separate, checked translation step (P-24).
        $result = $this->get('fr');

        $this->assertCount(2, $this->history, 'English answer, then the separate translation step (P-24)');
        $this->assertSame('translated', $result['commentary']['source'], 'Labelled as translated (issue #17)');
        $this->assertStringContainsString('Très bien', json_encode($result['commentary'], JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('Beta unit', json_encode($result['commentary']), 'Resolved locally after both steps');
        foreach ($this->history as $call) {
            $sent = (string) $call['request']->getBody();
            foreach (['Maria', 'Schmidt', 'Alpha unit', 'Beta unit', 'Secret course'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $sent, "Leaves the site: $forbidden");
            }
        }
        // P-26: core records both actions, with tokens only; the ledger uses the provider's token numbers.
        $this->assertSame(2, $DB->count_records('ai_action_register'));
        $stored = json_encode($DB->get_records('ai_action_generate_text'), JSON_UNESCAPED_UNICODE);
        foreach (['Maria', 'Alpha unit', 'Beta unit'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $stored);
        }
        $this->assertStringContainsString('[anonmod', $stored);
        $this->assertSame([2, 2], array_map('intval', $DB->get_fieldset_sql('SELECT tokens FROM {sideline_aiusage} ORDER BY id')));

        // Registered in the learner's user context, never in the course or the activity: the AI usage report of a
        // course would otherwise tell teachers who uses the coach.
        $usercontext = \context_user::instance($this->student->id);
        $this->assertSame([(int) $usercontext->id, (int) $usercontext->id], array_map('intval', $DB->get_fieldset_sql(
            'SELECT contextid FROM {ai_action_register} ORDER BY id'
        )));

        // A withdrawal empties prompt and response of this instance's actions in the AI subsystem (ai_records_test
        // shows that other instances and plugins keep theirs).
        $sideline = $DB->get_record('sideline', ['id' => $this->instance->id]);
        \mod_sideline\local\consent\consent_manager::record_decision(
            $sideline,
            (int) $this->student->id,
            false,
            \context_module::instance($this->instance->cmid)->id,
            time()
        );
        $this->assertSame(0, $DB->count_records_select('ai_action_generate_text', $DB->sql_like('prompt', '?'), ['%anon%']));
        $this->assertSame(0, $DB->count_records_select(
            'ai_action_generate_text',
            $DB->sql_like('generatedcontent', '?'),
            ['%anon%']
        ));
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
