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

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\writer;
use mod_sideline\local\consent\consent_manager;
use mod_sideline\local\service\commentary_service;
use mod_sideline\privacy\provider;
use mod_sideline\tests\core_ai_provider;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/sideline/tests/fixtures/core_ai_provider.php');
require_once($CFG->dirroot . '/mod/sideline/lib.php');

#[\PHPUnit\Framework\Attributes\CoversClass(ai_records::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(core_text_generator::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(consent_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
/**
 * Withdrawal and privacy requests empty in Moodle's AI subsystem exactly the entries of their instance
 * (issue #28): two instances and another plugin make AI calls for the same learner in the same user context.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_sideline\local\ai\ai_records
 * @covers \mod_sideline\local\ai\core_text_generator
 * @covers \mod_sideline\local\consent\consent_manager
 * @covers \mod_sideline\privacy\provider
 */
final class ai_records_test extends \advanced_testcase {
    use core_ai_provider;

    /** @var \stdClass Course. */
    private \stdClass $course;
    /** @var \stdClass Learner. */
    private \stdClass $student;
    /** @var \stdClass Instance A. */
    private \stdClass $a;
    /** @var \stdClass Instance B. */
    private \stdClass $b;
    /** @var array HTTP history. */
    private array $history = [];

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('enablecompletion', 1);
        $this->enable_core_ai_provider();
        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course(['enablecompletion' => 1]);
        $this->student = $gen->create_and_enrol($this->course, 'student');
        $page = $gen->create_module('page', ['course' => $this->course->id, 'name' => 'Alpha unit',
            'completion' => COMPLETION_TRACKING_MANUAL]);
        $this->setAdminUser();
        (new \completion_info($this->course))->update_state(
            get_coursemodule_from_instance('page', $page->id),
            COMPLETION_COMPLETE,
            $this->student->id
        );
        $this->a = $gen->create_module('sideline', ['course' => $this->course->id, 'aienabled' => 1]);
        $this->b = $gen->create_module('sideline', ['course' => $this->course->id, 'aienabled' => 1]);
        \core_ai\manager::user_policy_accepted((int) $this->student->id, \context_module::instance($this->a->cmid)->id);
    }

    /**
     * Commentary of an instance through the real core generator.
     *
     * @param \stdClass $instance Instance.
     * @return array
     */
    private function get(\stdClass $instance): array {
        global $DB;
        $this->setUser($this->student);
        return (new commentary_service())->get(
            $DB->get_record('sideline', ['id' => $instance->id]),
            $this->course,
            $this->student,
            \context_module::instance($instance->cmid),
            time()
        );
    }

    /**
     * An AI call of another plugin for the same learner in the same user context.
     *
     * @return int Id of its entry in ai_action_generate_text.
     */
    private function foreign_call(): int {
        global $DB;
        $action = new \core_ai\aiactions\generate_text(
            contextid: \context_user::instance($this->student->id)->id,
            userid: (int) $this->student->id,
            prompttext: 'Summarise my forum post, please'
        );
        $response = \core\di::get(\core_ai\manager::class)->process_action($action);
        $this->assertTrue($response->get_success());
        return (int) $DB->get_field_select(
            'ai_action_generate_text',
            'id',
            $DB->sql_compare_text('prompt') . ' = ?',
            ['Summarise my forum post, please']
        );
    }

    /**
     * The entries in ai_action_generate_text that an instance recorded as its own.
     *
     * @param \stdClass $instance Instance.
     * @return int[]
     */
    private function entries(\stdClass $instance): array {
        global $DB;
        return array_map('intval', $DB->get_fieldset_sql(
            'SELECT r.actionid
               FROM {sideline_aicall} a
               JOIN {ai_action_register} r ON r.id = a.actionid
              WHERE a.sidelineid = ?
           ORDER BY r.actionid',
            [$instance->id]
        ));
    }

    /**
     * Whether an entry still holds its prompt and answer.
     *
     * @param int $id Id in ai_action_generate_text.
     * @return bool
     */
    private function holds_text(int $id): bool {
        global $DB;
        $row = $DB->get_record('ai_action_generate_text', ['id' => $id], '*', MUST_EXIST);
        return (string) $row->prompt !== '' && (string) $row->generatedcontent !== '';
    }

    /**
     * Two instances and another plugin, each with a call that core recorded in the learner's user context.
     *
     * @return array [entries of A, entries of B, entry of the other plugin]
     */
    private function three_callers(): array {
        global $DB;
        $this->mock_provider(200, 3);
        $this->assertSame('ai', $this->get($this->a)['commentary']['source']);
        $this->assertSame('ai', $this->get($this->b)['commentary']['source']);
        $foreign = $this->foreign_call();
        $usercontext = (int) \context_user::instance($this->student->id)->id;
        $this->assertSame([$usercontext], array_map('intval', $DB->get_fieldset_sql(
            'SELECT DISTINCT contextid FROM {ai_action_register}'
        )), 'All three in the same user context');
        [$a, $b] = [$this->entries($this->a), $this->entries($this->b)];
        $this->assertCount(1, $a);
        $this->assertCount(1, $b);
        $this->assertNotContains($foreign, array_merge($a, $b), 'The other plugin\'s call is never attributed');
        return [$a, $b, $foreign];
    }

    public function test_a_withdrawal_in_one_instance_empties_only_its_own_entries(): void {
        global $DB;
        [$a, $b, $foreign] = $this->three_callers();
        $policies = $DB->count_records('ai_policy_register', ['userid' => $this->student->id]);

        $sideline = $DB->get_record('sideline', ['id' => $this->a->id]);
        consent_manager::record_decision(
            $sideline,
            (int) $this->student->id,
            false,
            \context_module::instance($this->a->cmid)->id,
            time()
        );
        $this->assertFalse($this->holds_text($a[0]), 'Prompt and answer of A are emptied');
        $this->assertTrue($this->holds_text($b[0]), 'B keeps its entry');
        $this->assertTrue($this->holds_text($foreign), 'The other plugin keeps its entry');
        $this->assertSame(
            $policies,
            $DB->count_records('ai_policy_register', ['userid' => $this->student->id]),
            'The acceptance of the AI policy is not the plugin\'s to delete'
        );
        $this->assertSame(3, $DB->count_records('ai_action_register'), 'Time and tokens stay, as after core\'s own deletion');
        $this->assertSame([], $this->entries($this->a), 'The records of origin go with it');

        // Repeated withdrawal: nothing else changes.
        consent_manager::forget_generated((int) $this->a->id, (int) $this->student->id);
        consent_manager::record_decision(
            $sideline,
            (int) $this->student->id,
            false,
            \context_module::instance($this->a->cmid)->id,
            time()
        );
        $this->assertTrue($this->holds_text($b[0]));
        $this->assertTrue($this->holds_text($foreign));
    }

    public function test_a_withdrawal_while_the_provider_answers_leaves_nothing_of_the_call(): void {
        global $DB;
        $this->mock_provider(200, 1);
        $foreign = $this->foreign_call();
        $sideline = $DB->get_record('sideline', ['id' => $this->a->id]);
        $userid = (int) $this->student->id;
        $contextid = \context_module::instance($this->a->cmid)->id;
        // The learner withdraws in another request while the provider is answering this one.
        $this->mock_provider(200, 1, function () use ($sideline, $userid, $contextid) {
            consent_manager::record_decision($sideline, $userid, false, $contextid, time());
        });
        $result = $this->get($this->a);
        $this->assertSame(commentary_service::STATUS_CONSENT_REQUIRED, $result['status']);
        $ids = $DB->get_fieldset_select('ai_action_generate_text', 'id', 'id <> ?', [$foreign]);
        $this->assertCount(1, $ids, 'Core recorded the call after the answer');
        $this->assertFalse($this->holds_text((int) $ids[0]), 'The final check emptied it');
        $this->assertTrue($this->holds_text($foreign), 'The other plugin keeps its entry');
    }

    public function test_privacy_requests_empty_only_the_entries_of_their_context(): void {
        global $DB;
        [$a, $b, $foreign] = $this->three_callers();
        $contexta = \context_module::instance($this->a->cmid);
        $contextb = \context_module::instance($this->b->cmid);

        // Export: the instance names the entries it caused; the texts belong to core's export of the user context.
        provider::export_user_data(new approved_contextlist($this->student, 'mod_sideline', [$contexta->id]));
        $exported = writer::with_context($contexta)->get_data([get_string('privacy:path:sideline_aicall', 'mod_sideline')]);
        $this->assertCount(1, $exported->items);

        provider::delete_data_for_user(new approved_contextlist($this->student, 'mod_sideline', [$contexta->id]));
        $this->assertFalse($this->holds_text($a[0]));
        $this->assertTrue($this->holds_text($b[0]));
        $this->assertTrue($this->holds_text($foreign));

        provider::delete_data_for_all_users_in_context($contextb);
        $this->assertFalse($this->holds_text($b[0]));
        $this->assertTrue($this->holds_text($foreign), 'The other plugin keeps its entry');
        $this->assertSame(0, $DB->count_records('sideline_aicall'));
    }

    public function test_deleting_an_instance_empties_its_entries(): void {
        [$a, $b, $foreign] = $this->three_callers();
        sideline_delete_instance((int) $this->a->id);
        $this->assertFalse($this->holds_text($a[0]));
        $this->assertTrue($this->holds_text($b[0]));
        $this->assertTrue($this->holds_text($foreign));
    }

    public function test_the_upgrade_attributes_only_prompts_of_the_plugin_in_the_activity_context(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/sideline/db/upgrade.php');
        $context = \context_module::instance($this->a->cmid)->id;
        $entry = static function (string $prompt) use ($DB, $context): int {
            $register = ['actionname' => 'generate_text', 'success' => 1, 'userid' => 2, 'contextid' => $context,
                'provider' => 'aiprovider_openai', 'timecreated' => time(), 'timecompleted' => time()];
            if (array_key_exists('courseid', $DB->get_columns('ai_action_register'))) {
                $register['courseid'] = 0;
            }
            $register['actionid'] = $DB->insert_record('ai_action_generate_text', (object) ['prompt' => $prompt,
                'generatedcontent' => 'x']);
            return (int) $DB->insert_record('ai_action_register', (object) $register);
        };
        // As releases before 0.4.1 registered it, and a call of the text editor while a teacher edits the activity.
        $own = $entry("You phrase.\n\nFACTS:\n{}\n\nOUTPUT FORMAT: respond with JSON only, no other text:\n{}");
        $editor = $entry('Improve the description of this activity.');
        mod_sideline_upgrade_attribute_ai_calls();
        $attributed = array_map('intval', $DB->get_fieldset_select(
            'sideline_aicall',
            'actionid',
            'sidelineid = ?',
            [$this->a->id]
        ));
        $this->assertSame([$own], $attributed);
        $this->assertNotContains($editor, $attributed);

        // Run again (an interrupted upgrade): nothing is attributed twice.
        mod_sideline_upgrade_attribute_ai_calls();
        $this->assertSame(1, $DB->count_records('sideline_aicall', ['actionid' => $own]));
    }
}
