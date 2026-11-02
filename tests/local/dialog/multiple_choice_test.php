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
use mod_sideline\external\get_commentary;
use mod_sideline\external\get_my_answers;
use mod_sideline\local\transfer\config_transfer;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/webservice/tests/helpers.php');

#[\PHPUnit\Framework\Attributes\CoversClass(question_type::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(dialog_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(answer_question::class)]
/**
 * Multiple choice with several selections (issue #11, P-16 to P-19).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_sideline\local\dialog\question_type
 * @covers \mod_sideline\local\dialog\dialog_manager
 * @covers \mod_sideline\external\answer_question
 */
final class multiple_choice_test extends \advanced_testcase {
    /**
     * A normalised multiple selection configuration.
     *
     * @param array $overrides Overrides.
     * @return array
     */
    private static function config(array $overrides = []): array {
        [$config, $errors] = question_type::normalise_config('mc', $overrides + [
            'options' => [
                ['label' => 'Little time', 'effect' => 'focus_deadlines'],
                ['label' => 'Tired', 'effect' => 'pause_ok'],
                ['label' => 'Overwhelmed', 'effect' => 'small_steps'],
                ['label' => 'All fine', 'effect' => 'none'],
            ],
            'multiple' => 1, 'minselect' => 1, 'maxselect' => 3,
        ]);
        return [$config, $errors];
    }

    public function test_single_and_multiple_choice_are_configured_separately(): void {
        [$single] = question_type::normalise_config('mc', ['options' => [['label' => 'A'], ['label' => 'B']]]);
        $this->assertFalse($single['multiple']);
        $this->assertSame(1, $single['maxselect']);
        [$multi, $errors] = self::config();
        $this->assertSame([], $errors);
        $this->assertTrue($multi['multiple']);
        $this->assertSame(3, $multi['maxselect']);
        [, $errors] = self::config(['minselect' => 3, 'maxselect' => 2]);
        $this->assertNotEmpty($errors);
        [, $errors] = self::config(['maxselect' => 9]);
        $this->assertNotEmpty($errors, 'More selections than options');
    }

    /**
     * Answer values.
     *
     * @return array
     */
    public static function value_provider(): array {
        return [
            'two options' => ['[2,0]', '[0,2]'],
            'one option' => ['[3]', '[3]'],
            'array value' => [[1, 2], '[1,2]'],
            'empty' => ['[]', null],
            'too many' => ['[0,1,2,3]', null],
            'duplicate' => ['[1,1]', null],
            'unknown option' => ['[7]', null],
            'negative' => ['[-1]', null],
            'not a list' => ['{"a":1}', null],
            'float' => ['[1.5]', null],
            'scalar' => ['1', null],
            'garbage' => ['nonsense', null],
        ];
    }

    /**
     * Test with data provider.
     *
     * @dataProvider value_provider
     * @param mixed $value Raw value.
     * @param string|null $stored Expected stored value.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('value_provider')]
    public function test_values_are_validated_strictly(mixed $value, ?string $stored): void {
        [$config] = self::config();
        $this->assertSame($stored, question_type::normalise_value('mc', $config, $value));
    }

    public function test_effects_of_several_options_combine_deterministically(): void {
        [$config] = self::config();
        $this->assertSame('focus_deadlines', question_type::effect('mc', $config, '[0,1,2]'), 'Highest precedence wins');
        $this->assertSame('small_steps', question_type::effect('mc', $config, '[1,2]'));
        $this->assertSame('pause_ok', question_type::effect('mc', $config, '[1,3]'));
        $this->assertSame('none', question_type::effect('mc', $config, '[3]'));
        [$none] = self::config(['combine' => 'none']);
        $this->assertSame('none', question_type::effect('mc', $none, '[0,1]'), 'Configured: no effect for several');
    }

    public function test_answer_view_edit_delete_and_transfer_support_several_options(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $student = $gen->create_and_enrol($course, 'student');
        $instance = $gen->create_module('sideline', ['course' => $course->id, 'dialogenabled' => 1]);
        [$config] = self::config();
        $qid = $gen->get_plugin_generator('mod_sideline')->create_question(['sidelineid' => $instance->id, 'config' => $config]);

        $this->setUser($student);
        $panel = external_api::clean_returnvalue(get_commentary::execute_returns(), get_commentary::execute($instance->cmid));
        $this->assertTrue($panel['question'][0]['multiple']);
        $this->assertSame(1, $panel['question'][0]['minselect']);
        $this->assertSame(3, $panel['question'][0]['maxselect']);

        answer_question::execute($instance->cmid, $qid, '[0,1]');
        $answers = external_api::clean_returnvalue(get_my_answers::execute_returns(), get_my_answers::execute($instance->cmid));
        $this->assertSame('Little time, Tired', $answers['answers'][0]['answer']);
        $this->assertSame(get_string('effect_focus_deadlines', 'mod_sideline'), $answers['answers'][0]['effect']);

        answer_question::execute($instance->cmid, $qid, '[3]');
        $this->assertSame(1, $DB->count_records('sideline_answer', ['userid' => $student->id]));
        $this->assertSame('[3]', $DB->get_field('sideline_answer', 'value', ['userid' => $student->id]));

        try {
            answer_question::execute($instance->cmid, $qid, '[0,0]');
            $this->fail('A duplicate selection must be rejected');
        } catch (\moodle_exception $e) {
            $this->assertSame('[3]', $DB->get_field('sideline_answer', 'value', ['userid' => $student->id]));
        }

        // The configuration travels with export and import, the answer never does.
        $this->setAdminUser();
        $export = config_transfer::export($DB->get_record('sideline', ['id' => $instance->id]));
        $this->assertTrue($export['questions'][0]['config']['multiple']);
        $this->assertStringNotContainsString('Little time, Tired', json_encode($export));
    }
}
