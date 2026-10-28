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

use core_external\external_api;
use mod_sideline\external\answer_question;
use mod_sideline\external\delete_answer;
use mod_sideline\external\get_commentary;
use mod_sideline\external\get_my_answers;
use mod_sideline\local\ai\ai_gateway;
use mod_sideline\local\service\commentary_service;
use mod_sideline\tests\fake_text_generator;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/fixtures/fake_text_generator.php');
require_once($CFG->dirroot . '/webservice/tests/helpers.php');

/**
 * Dialog and interview engine (P1-08).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_sideline\local\dialog\question_type
 * @covers \mod_sideline\local\dialog\dialog_manager
 * @covers \mod_sideline\external\answer_question
 * @covers \mod_sideline\external\get_my_answers
 * @covers \mod_sideline\local\service\rule_evaluator
 * @covers \mod_sideline\local\service\commentary_service
 * @covers \mod_sideline\local\cache\commentary_cache
 */
#[\PHPUnit\Framework\Attributes\CoversClass(question_type::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(dialog_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(answer_question::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(get_my_answers::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_sideline\local\service\rule_evaluator::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_sideline\local\service\commentary_service::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_sideline\local\cache\commentary_cache::class)]
final class dialog_test extends \advanced_testcase {
    /** @var \stdClass Course. */
    private \stdClass $course;
    /** @var \stdClass Learner. */
    private \stdClass $student;
    /** @var \stdClass Instance. */
    private \stdClass $sideline;
    /** @var int Course module id. */
    private int $cmid;

    #[\Override]
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        set_config('enablecompletion', 1);
        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course(['enablecompletion' => 1]);
        $this->student = $gen->create_and_enrol($this->course, 'student');
        $manual = ['completion' => COMPLETION_TRACKING_MANUAL];
        $gen->create_module('page', ['course' => $this->course->id, 'name' => 'First step'] + $manual);
        $gen->create_module('page', [
            'course' => $this->course->id,
            'name' => 'Due soon',
            'completionexpected' => time() + 5 * DAYSECS,
        ]
            + $manual);
        $instance = $gen->create_module(
            'sideline',
            ['course' => $this->course->id, 'dialogenabled' => 1, 'dialogcooldown' => DAYSECS],
        );
        $this->sideline = $DB->get_record('sideline', ['id' => $instance->id]);
        $this->cmid = (int) $instance->cmid;
    }

    /**
     * Create a question.
     *
     * @param array $record Record.
     * @return int
     */
    private function question(array $record): int {
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_sideline');
        return $generator->create_question(['sidelineid' => $this->sideline->id] + $record);
    }

    /**
     * Commentary for the student.
     *
     * @param fake_text_generator|null $fake AI double.
     * @param int|null $now Time.
     * @return array
     */
    private function get(?fake_text_generator $fake = null, ?int $now = null): array {
        global $DB;
        $this->setUser($this->student);
        $record = $DB->get_record('sideline', ['id' => $this->sideline->id]);
        return (new commentary_service(new ai_gateway($fake ?? new fake_text_generator())))
            ->get($record, $this->course, $this->student, \context_module::instance($this->cmid), $now ?? time());
    }

    /**
     * Data provider.
     *
     * @return array
     */
    public static function config_provider(): array {
        return [
            'mc ok' => ['mc', ['options' => [['label' => 'A'], ['label' => 'B', 'effect' => 'small_steps']]], true],
            'mc one option' => ['mc', ['options' => [['label' => 'A']]], false],
            'slider ok' => ['slider', ['min' => 0, 'max' => 10, 'step' => 1], true],
            'slider inverted' => ['slider', ['min' => 5, 'max' => 1], false],
            'semdiff ok' => ['semdiff', ['pairs' => [['tense', 'relaxed']], 'scale' => 7], true],
            'semdiff empty' => ['semdiff', ['pairs' => []], false],
            'text ok' => ['text', ['maxlength' => 300], true],
            'unknown type' => ['essay', [], false],
        ];
    }

    /**
     * Test with data provider.
     *
     * @dataProvider config_provider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('config_provider')]
    public function test_question_configurations_are_validated(string $type, array $config, bool $valid): void {
        [, $errors] = question_type::normalise_config($type, $config);
        $this->assertSame($valid, !$errors);
    }

    public function test_answer_values_are_validated_per_type(): void {
        [$mc] = question_type::normalise_config('mc', ['options' => [['label' => 'A'], ['label' => 'B']]]);
        $this->assertSame('1', question_type::normalise_value('mc', $mc, 1));
        $this->assertNull(question_type::normalise_value('mc', $mc, 5));
        [$slider] = question_type::normalise_config('slider', ['min' => 0, 'max' => 10, 'step' => 2]);
        $this->assertSame('4', question_type::normalise_value('slider', $slider, '4'));
        $this->assertNull(question_type::normalise_value('slider', $slider, '3'));
        $this->assertNull(question_type::normalise_value('slider', $slider, '12'));
        [$semdiff] = question_type::normalise_config('semdiff', ['pairs' => [['a', 'b'], ['c', 'd']], 'scale' => 5]);
        $this->assertSame('[1,5]', question_type::normalise_value('semdiff', $semdiff, '[1,5]'));
        $this->assertNull(question_type::normalise_value('semdiff', $semdiff, '[1,6]'));
        $this->assertNull(question_type::normalise_value('semdiff', $semdiff, '[1]'));
        [$text] = question_type::normalise_config('text', ['maxlength' => 10]);
        $this->assertNull(question_type::normalise_value('text', $text, str_repeat('x', 11)));
        $this->assertSame('"hello"', question_type::normalise_value('text', $text, '<b>hello</b>'));
    }

    public function test_effects_of_slider_and_differential_follow_the_threshold(): void {
        [$slider] = question_type::normalise_config('slider', ['min' => 0, 'max' => 10, 'threshold' => 5,
            'below' => 'pause_ok', 'atorabove' => 'none']);
        $this->assertSame('pause_ok', question_type::effect('slider', $slider, '3'));
        $this->assertSame('none', question_type::effect('slider', $slider, '5'));
        [$semdiff] = question_type::normalise_config('semdiff', ['pairs' => [['a', 'b'], ['c', 'd']], 'threshold' => 4,
            'below' => 'small_steps', 'atorabove' => 'none']);
        $this->assertSame('small_steps', question_type::effect('semdiff', $semdiff, '[2,3]'));
        // Free text never has an effect.
        [$text] = question_type::normalise_config('text', []);
        $this->assertSame('none', question_type::effect('text', $text, '"I need small steps"'));
    }

    public function test_a_due_question_is_offered_and_respects_trigger_cooldown_and_skip(): void {
        $any = $this->question(['qtype' => 'mc', 'triggerkey' => 'any']);
        $result = $this->get();
        $this->assertSame($any, $result['question']['id']);

        // Skipping counts as an interaction: no question within the instance cooldown.
        dialog_manager::answer($this->sideline, (int) $this->student->id, $any, null, true, time());
        $this->assertNull($this->get()['question']);
        // After the cooldown a second question may come, but the skipped one does not return (no own cooldown).
        $second = $this->question(['qtype' => 'slider', 'config' => ['min' => 0, 'max' => 10], 'triggerkey' => 'any']);
        $later = $this->get(null, time() + 2 * DAYSECS);
        $this->assertSame($second, $later['question']['id']);

        // A question bound to a message only appears with that message.
        $this->question(['qtype' => 'mc', 'triggerkey' => 'course_complete']);
        dialog_manager::answer($this->sideline, (int) $this->student->id, $second, 5, false, time() + 2 * DAYSECS);
        $this->assertNull($this->get(null, time() + 4 * DAYSECS)['question']);
    }

    public function test_answers_change_the_commentary_only_through_the_named_effect(): void {
        $qid = $this->question(['qtype' => 'mc', 'config' => ['options' => [
            ['label' => 'All good', 'effect' => 'none'], ['label' => 'Overwhelmed', 'effect' => 'small_steps'],
        ]]]);
        $before = array_column($this->get()['commentary']['blocks'], 'key');
        $this->assertNotContains('small_step', $before);
        $this->assertNotContains('deadline_soon', $before);

        dialog_manager::answer($this->sideline, (int) $this->student->id, $qid, 1, false, time());
        $after = array_column($this->get()['commentary']['blocks'], 'key');
        $this->assertContains('small_step', $after);

        $deadlines = $this->question(['qtype' => 'mc', 'config' => ['options' => [
            ['label' => 'Short on time', 'effect' => 'focus_deadlines'], ['label' => 'Fine'],
        ]]]);
        dialog_manager::answer($this->sideline, (int) $this->student->id, $deadlines, 0, false, time());
        // The deadline five days ahead now falls into the wider window.
        $this->assertContains('deadline_soon', array_column($this->get()['commentary']['blocks'], 'key'));
    }

    public function test_free_text_is_never_sent_to_ai(): void {
        set_config('aienabled', 1, 'mod_sideline');
        global $DB;
        $DB->set_field('sideline', 'aienabled', 1, ['id' => $this->sideline->id]);
        $qid = $this->question(['qtype' => 'text', 'config' => ['maxlength' => 200]]);
        dialog_manager::answer($this->sideline, (int) $this->student->id, $qid, 'My private worries', false, time());
        $fake = new fake_text_generator();
        $fake->responder = fake_text_generator::echoing_tokens();
        $this->get($fake);
        $this->assertSame(1, $fake->calls);
        $this->assertStringNotContainsString('private worries', implode("\n", $fake->prompts));
    }

    public function test_learners_see_edit_and_delete_their_own_answers_via_the_api(): void {
        global $DB;
        $qid = $this->question(['qtype' => 'mc']);
        $this->setUser($this->student);
        external_api::clean_returnvalue(answer_question::execute_returns(), answer_question::execute($this->cmid, $qid, '1'));
        $answers = external_api::clean_returnvalue(get_my_answers::execute_returns(), get_my_answers::execute($this->cmid));
        $this->assertCount(1, $answers['answers']);
        $this->assertSame('Short on time', $answers['answers'][0]['answer']);
        $this->assertSame(get_string('effect_focus_deadlines', 'mod_sideline'), $answers['answers'][0]['effect']);

        // Changing the answer replaces it.
        answer_question::execute($this->cmid, $qid, '0');
        $this->assertSame(1, $DB->count_records('sideline_answer', ['userid' => $this->student->id]));

        // Another learner sees nothing of it.
        $other = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->setUser($other);
        $this->assertSame([], get_my_answers::execute($this->cmid)['answers']);

        $this->setUser($this->student);
        delete_answer::execute($this->cmid, $qid);
        $this->assertSame(0, $DB->count_records('sideline_answer', ['userid' => $this->student->id]));
    }

    public function test_invalid_or_foreign_questions_are_rejected(): void {
        $qid = $this->question(['qtype' => 'mc']);
        $this->setUser($this->student);
        try {
            answer_question::execute($this->cmid, $qid, '9');
            $this->fail('Invalid option accepted');
        } catch (\invalid_parameter_exception $e) {
            $this->assertStringContainsString('Invalid answer', $e->getMessage());
        }
        $otherinstance = $this->getDataGenerator()->create_module(
            'sideline',
            ['course' => $this->course->id, 'dialogenabled' => 1],
        );
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_sideline');
        $foreign = $generator->create_question(['sidelineid' => $otherinstance->id]);
        $this->expectException(\invalid_parameter_exception::class);
        answer_question::execute($this->cmid, $foreign, '0');
    }

    public function test_the_panel_receives_the_question_without_any_answer_data(): void {
        $this->question(['qtype' => 'semdiff', 'prompt' => 'How do you feel?', 'config' => ['pairs' => [['tense', 'relaxed']]]]);
        $this->setUser($this->student);
        $result = external_api::clean_returnvalue(get_commentary::execute_returns(), get_commentary::execute($this->cmid));
        $this->assertCount(1, $result['question']);
        $this->assertSame('semdiff', $result['question'][0]['qtype']);
        $this->assertSame('tense', $result['question'][0]['pairs'][0]['left']);
    }
}
