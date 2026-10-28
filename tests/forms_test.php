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

namespace mod_sideline\form;

use mod_sideline\local\dialog\dialog_manager;
use mod_sideline\local\dialog\question_type;

/**
 * Validation and data mapping of the teacher and administrator forms.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_sideline\form\question_form
 * @covers \mod_sideline\form\rule_form
 * @covers \mod_sideline\form\persona_form
 * @covers \mod_sideline\form\debug_form
 * @covers \mod_sideline\form\transfer_form
 */
#[\PHPUnit\Framework\Attributes\CoversClass(question_form::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(rule_form::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(persona_form::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(debug_form::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(transfer_form::class)]
final class forms_test extends \advanced_testcase {
    /**
     * The QuickForm behind a moodleform.
     *
     * @param \moodleform $form Form.
     * @return \MoodleQuickForm
     */
    private static function quickform(\moodleform $form): \MoodleQuickForm {
        $property = new \ReflectionProperty(\moodleform::class, '_form');
        return $property->getValue($form);
    }

    /**
     * Form data of every question type.
     *
     * @return array
     */
    public static function question_data(): array {
        return [
            'mc' => [['qtype' => question_type::MC, 'mcoptions' => "Fine | none\nShort on time | focus_deadlines"]],
            'text' => [['qtype' => question_type::TEXT, 'maxlength' => 200]],
            'slider' => [['qtype' => question_type::SLIDER, 'slidermin' => 0, 'slidermax' => 10, 'sliderstep' => 1,
                'sliderminlabel' => 'Low', 'slidermaxlabel' => 'High', 'threshold' => '5', 'below' => 'small_steps',
                'atorabove' => 'none']],
            'semdiff' => [['qtype' => question_type::SEMDIFF, 'pairs' => "easy | hard\nboring | exciting", 'scale' => 5,
                'threshold' => '', 'below' => 'none', 'atorabove' => 'none']],
        ];
    }

    /**
     * Form data becomes a stored question and the question becomes form data again, unchanged.
     *
     * @dataProvider question_data
     * @param array $data Form data.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('question_data')]
    public function test_question_round_trip(array $data): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module('sideline', ['course' => $course->id]);
        $id = dialog_manager::save_question((int) $instance->id, (object) [
            'qtype' => $data['qtype'], 'prompt' => 'How?', 'config' => question_form::config_from($data),
            'triggerkey' => 'any', 'cooldown' => 0, 'enabled' => 1,
        ]);
        $question = dialog_manager::questions((int) $instance->id)[$id];
        $back = question_form::data_from($question);
        $this->assertSame(question_form::config_from($data), question_form::config_from($back + $data));
        $this->assertSame($data['qtype'], $back['qtype']);
    }

    public function test_question_form_validation_and_policy_restricted_types(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('interviewtypes', 'mc,slider', 'mod_sideline');
        $form = new question_form(null, ['triggers' => ['any' => 'Any']]);
        $options = self::quickform($form)->getElement('qtype')->_options;
        $this->assertSame(['mc', 'slider'], array_map(static fn($o) => $o['attr']['value'], $options));

        // An existing question keeps its type selectable, so it can be disabled without losing its answers.
        $existing = new question_form(null, ['triggers' => ['any' => 'Any'], 'currenttype' => 'text']);
        $options = self::quickform($existing)->getElement('qtype')->_options;
        $this->assertSame(['mc', 'text', 'slider'], array_map(static fn($o) => $o['attr']['value'], $options));
        $this->assertArrayHasKey('qtype', $form->validation(['threshold' => ''], []), 'No type at all');

        $errors = $form->validation(['qtype' => question_type::MC, 'mcoptions' => '', 'threshold' => 'x'], []);
        $this->assertArrayHasKey('qtype', $errors, 'A choice question needs options');
        $this->assertArrayHasKey('threshold', $errors);
    }

    public function test_rule_form_validates_the_definition(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $form = new rule_form(null, ['cmids' => [5]]);
        $errors = $form->validation(['definition' => '{"key":"nope"}'], []);
        $this->assertArrayHasKey('definition', $errors);
        $valid = json_encode(['key' => 'custom_ok', 'conditions' => [['type' => 'course_complete', 'is' => 'yes']],
            'templates' => ['en' => 'Done.']]);
        $this->assertSame([], $form->validation(['definition' => $valid], []));
    }

    public function test_persona_form_validation(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $form = new persona_form();
        $errors = $form->validation(['id' => 0, 'shortname' => 'A b', 'name' => '', 'style' => '<b>x</b>',
            'opener' => 'see https://example.com'], []);
        $this->assertSame(['shortname', 'name', 'style', 'opener'], array_keys($errors));
        $this->assertSame([], $form->validation(['id' => 0, 'shortname' => 'calm', 'name' => 'Calm',
            'style' => 'You are calm.', 'opener' => 'Hello.'], []));
    }

    public function test_debug_form_needs_a_purpose_and_a_valid_window(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $form = new debug_form();
        $errors = $form->validation(['cmid' => 1, 'userid' => 2, 'purpose' => 'short', 'timefrom' => 100, 'timeto' => 50], []);
        $this->assertSame(['purpose', 'timeto'], array_keys($errors));
        $this->assertSame([], $form->validation(['cmid' => 1, 'userid' => 2, 'purpose' => 'Support request 123',
            'timefrom' => 50, 'timeto' => 100], []));
    }

    public function test_transfer_form_offers_every_part_and_conflict_strategy(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $form = new transfer_form();
        $mform = self::quickform($form);
        foreach (\mod_sideline\local\transfer\config_transfer::PARTS as $part) {
            $this->assertTrue($mform->elementExists('part_' . $part));
        }
        $this->assertTrue($mform->elementExists('conflict'));
        $this->assertTrue($mform->elementExists('confirm'));
    }
}
