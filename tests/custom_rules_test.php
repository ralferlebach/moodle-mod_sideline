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

namespace mod_sideline\local\rules;

use mod_sideline\local\ai\ai_gateway;
use mod_sideline\local\model\activity_state;
use mod_sideline\local\service\commentary_service;
use mod_sideline\local\service\evaluation_context;
use mod_sideline\local\service\rule_evaluator;
use mod_sideline\local\transfer\config_transfer;
use mod_sideline\tests\fake_text_generator;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/fake_text_generator.php');

/**
 * Custom rules, templates, cooldowns, preview and configuration transfer (P1-09).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_sideline\local\rules\rule_schema
 * @covers \mod_sideline\local\rules\rule_store
 * @covers \mod_sideline\local\rules\rule_preview
 * @covers \mod_sideline\local\transfer\config_transfer
 * @covers \mod_sideline\local\service\rule_evaluator
 * @covers \mod_sideline\local\service\message_composer
 * @covers \mod_sideline\local\ai\pseudonymizer
 */
#[\PHPUnit\Framework\Attributes\CoversClass(rule_schema::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(rule_store::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(rule_preview::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(config_transfer::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_sideline\local\service\rule_evaluator::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_sideline\local\service\message_composer::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_sideline\local\ai\pseudonymizer::class)]
final class custom_rules_test extends \advanced_testcase {
    /** @var int Fixed time. */
    private const NOW = 1800000000;

    /**
     * A valid rule.
     *
     * @param array $overrides Overrides.
     * @return array
     */
    private static function rule(array $overrides = []): array {
        return $overrides + [
            'key' => 'custom_reminder',
            'category' => 'next',
            'priority' => 10,
            'match' => 'all',
            'conditions' => [['type' => 'activity', 'cmid' => 0, 'field' => 'completion', 'is' => 'no']],
            'action' => 'open',
            'templates' => ['en' => 'Do not forget {label}.', 'de' => 'Denk an {label}.'],
        ];
    }

    /**
     * Data provider.
     *
     * @return array
     */
    public static function invalid_provider(): array {
        return [
            'bad key' => [['key' => 'reminder']],
            'markup' => [['templates' => ['en' => '<b>Hi</b> {label}']]],
            'link' => [['templates' => ['en' => 'See https://example.com']]],
            'unknown placeholder' => [['templates' => ['en' => 'Hi {firstname}']]],
            'no conditions' => [['conditions' => []]],
            'unknown condition' => [['conditions' => [['type' => 'php', 'code' => 'exit']]]],
            'foreign cmid' => [['conditions' => [['type' => 'activity', 'cmid' => 999999, 'field' => 'completion', 'is' => 'no']]]],
            'bad category' => [['category' => 'urgent']],
            'not json array' => [['templates' => 'Hello']],
        ];
    }

    /**
     * Test with data provider.
     *
     * @dataProvider invalid_provider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_provider')]
    public function test_invalid_definitions_are_rejected(array $overrides): void {
        $this->resetAfterTest();
        [$clean, $errors] = rule_schema::validate(self::rule($overrides), [1, 2, 3]);
        $this->assertNull($clean);
        $this->assertNotEmpty($errors);
    }

    public function test_a_valid_definition_is_normalised(): void {
        $this->resetAfterTest();
        [$clean, $errors] = rule_schema::validate(self::rule(['priority' => -5, 'cooldown' => '60']), []);
        $this->assertSame([], $errors);
        $this->assertSame(0, $clean['priority']);
        $this->assertSame(60, $clean['cooldown']);
    }

    /**
     * Evaluate with one custom rule.
     *
     * @param array $rule Rule.
     * @param activity_state[] $states States.
     * @param array $hits Hits.
     * @param string[] $current Current keys.
     * @return string[] Selected keys.
     */
    private function keys(array $rule, array $states, array $hits = [], array $current = []): array {
        [$clean] = rule_schema::validate($rule, array_keys($states));
        $ctx = new evaluation_context(customrules: [$clean], hits: $hits, currentkeys: $current);
        $recs = (new rule_evaluator())->evaluate($states, self::NOW, $ctx);
        return array_map(static fn($r) => $r->key, $recs);
    }

    /**
     * A state.
     *
     * @param int $cmid Id.
     * @param array $fields Fields.
     * @return activity_state
     */
    private static function state(int $cmid, array $fields = []): activity_state {
        return new activity_state(...($fields + [
            'cmid' => $cmid, 'label' => 'Unit ' . $cmid, 'visible' => true, 'available' => activity_state::YES,
            'completion' => activity_state::NO,
        ]));
    }

    public function test_conditions_all_and_any(): void {
        $this->resetAfterTest();
        $states = [1 => self::state(1, ['grade' => 40.0, 'completion' => activity_state::YES]), 2 => self::state(2)];
        $rule = self::rule(['key' => 'custom_low', 'category' => 'safety', 'conditions' => [
            ['type' => 'grade', 'cmid' => 0, 'op' => 'lt', 'value' => 50],
            ['type' => 'activity', 'cmid' => 0, 'field' => 'completion', 'is' => 'yes'],
        ]]);
        $this->assertSame('custom_low', $this->keys($rule, $states)[0]);

        $rule['conditions'][1]['is'] = 'no';
        $this->assertNotContains('custom_low', $this->keys($rule, $states));
        $rule['match'] = 'any';
        $this->assertContains('custom_low', $this->keys($rule, $states));
    }

    public function test_unknown_values_never_satisfy_a_condition(): void {
        $this->resetAfterTest();
        $states = [1 => self::state(1, ['completion' => activity_state::UNKNOWN])];
        $rule = self::rule(['conditions' => [['type' => 'unused_for', 'cmid' => 0, 'seconds' => DAYSECS]]]);
        $this->assertNotContains('custom_reminder', $this->keys($rule, $states));
        $rule['conditions'] = [['type' => 'activity', 'cmid' => 0, 'field' => 'completion', 'is' => 'no']];
        $this->assertNotContains('custom_reminder', $this->keys($rule, $states));
    }

    public function test_cooldown_and_weekly_limit_but_a_shown_rule_stays(): void {
        $this->resetAfterTest();
        $states = [1 => self::state(1)];
        $rule = self::rule(['cooldown' => 2 * DAYSECS, 'maxperweek' => 2]);
        $this->assertContains('custom_reminder', $this->keys($rule, $states));
        // Shown one day ago: in cooldown...
        $hits = ['custom_reminder' => [self::NOW - DAYSECS]];
        $this->assertNotContains('custom_reminder', $this->keys($rule, $states, $hits));
        // ...unless it is the message currently shown.
        $this->assertContains('custom_reminder', $this->keys($rule, $states, $hits, ['custom_reminder']));
        // Weekly limit reached.
        $hits = ['custom_reminder' => [self::NOW - 3 * DAYSECS, self::NOW - 4 * DAYSECS]];
        $this->assertNotContains('custom_reminder', $this->keys($rule, $states, $hits));
        // The cached text expires when the weekly limit frees up, if that is before the safety lifetime.
        $hits = ['custom_reminder' => [self::NOW - 3 * DAYSECS, self::NOW - WEEKSECS + HOURSECS]];
        $evaluator = new rule_evaluator();
        [$clean] = rule_schema::validate($rule, [1]);
        $evaluator->evaluate($states, self::NOW, new evaluation_context(customrules: [$clean], hits: $hits));
        $this->assertSame(self::NOW + HOURSECS, $evaluator->next_transition($states, self::NOW));
    }

    public function test_custom_text_in_the_learner_language_with_hits_recorded(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        set_config('enablecompletion', 1);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Lab report',
            'completion' => COMPLETION_TRACKING_MANUAL]);
        $instance = $this->getDataGenerator()->create_module('sideline', ['course' => $course->id]);
        $sideline = $DB->get_record('sideline', ['id' => $instance->id]);
        $this->getDataGenerator()->get_plugin_generator('mod_sideline')->create_rule($sideline, self::rule([
            'category' => 'safety', 'cooldown' => DAYSECS,
        ]));
        $sideline = $DB->get_record('sideline', ['id' => $instance->id]);
        $this->setUser($student);
        $result = (new commentary_service(new ai_gateway(new fake_text_generator())))
            ->get($sideline, $course, $student, \context_module::instance($instance->cmid), time());
        $this->assertSame('custom_reminder', $result['commentary']['blocks'][0]['key']);
        $this->assertStringContainsString('Do not forget Lab report.', $result['commentary']['blocks'][0]['text']);
        $this->assertStringContainsString(
            'mod/page/view.php?id=' . $page->cmid,
            $result['commentary']['blocks'][0]['action']['url'],
        );
        $this->assertSame(1, $DB->count_records('sideline_rulehit', ['userid' => $student->id, 'rulekey' => 'custom_reminder']));
    }

    public function test_preview_uses_sample_data_only(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Sample unit']);
        $instance = $this->getDataGenerator()->create_module('sideline', ['course' => $course->id]);
        $sideline = $DB->get_record('sideline', ['id' => $instance->id]);
        [$clean] = rule_schema::validate(self::rule(['category' => 'safety']), []);
        $before = $DB->count_records('sideline_cache');
        $blocks = rule_preview::preview($sideline, $clean, 'none_done');
        $this->assertSame('custom_reminder', $blocks[0]['key']);
        $this->assertStringContainsString('Sample unit', $blocks[0]['text']);
        $this->assertSame($before, $DB->count_records('sideline_cache'));
    }

    public function test_export_import_round_trip_with_mapping_conflicts_and_no_personal_data(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $source = $gen->create_course();
        $quiz = $gen->create_module('page', ['course' => $source->id, 'name' => 'Week 1']);
        $instance = $gen->create_module('sideline', ['course' => $source->id, 'persona' => 'reporter', 'scopetype' => 'activities',
            'scopecmids' => [$quiz->cmid], 'dialogenabled' => 1]);
        $sideline = $DB->get_record('sideline', ['id' => $instance->id]);
        $plugin = $gen->get_plugin_generator('mod_sideline');
        $plugin->create_rule($sideline, self::rule(['conditions' => [['type' => 'activity', 'cmid' => (int) $quiz->cmid,
            'field' => 'completion', 'is' => 'no']]]));
        $qid = $plugin->create_question(['sidelineid' => $sideline->id, 'qtype' => 'text', 'config' => ['maxlength' => 100]]);
        $student = $gen->create_and_enrol($source, 'student');
        $sideline = $DB->get_record('sideline', ['id' => $instance->id]);
        \mod_sideline\local\dialog\dialog_manager::answer($sideline, (int) $student->id, $qid, 'Personal secret', false, time());

        $export = config_transfer::export($sideline);
        $json = json_encode($export);
        $this->assertStringNotContainsString('Personal secret', $json);
        $this->assertStringNotContainsString((string) $student->id . '"', str_replace('"userid"', '', $json));
        $this->assertSame('Week 1', $export['scope']['activities'][0]['name']);

        $target = $gen->create_course();
        $mapped = $gen->create_module('page', ['course' => $target->id, 'name' => 'Week 1']);
        $tinstance = $gen->create_module('sideline', ['course' => $target->id]);
        $tsideline = $DB->get_record('sideline', ['id' => $tinstance->id]);
        $data = config_transfer::migrate(json_decode($json, true));
        $preview = config_transfer::preview($tsideline, $data, config_transfer::PARTS, 'replace');
        $this->assertNotEmpty($preview['changes']);
        $this->assertSame([], $preview['warnings']);
        $this->assertSame(0, $DB->count_records('sideline_rule', ['sidelineid' => $tsideline->id]), 'Preview must not write');

        config_transfer::apply($tsideline, $preview['plan']);
        $tsideline = $DB->get_record('sideline', ['id' => $tinstance->id]);
        $this->assertSame('reporter', $tsideline->persona);
        $this->assertSame([(int) $mapped->cmid], \mod_sideline\local\instance_config::get_scope_activities((int) $tsideline->id));
        $rules = rule_store::definitions((int) $tsideline->id);
        $this->assertSame((int) $mapped->cmid, $rules[0]['conditions'][0]['cmid']);
        $this->assertSame(1, $DB->count_records('sideline_question', ['sidelineid' => $tsideline->id]));
        $this->assertSame(0, $DB->count_records('sideline_answer', ['sidelineid' => $tsideline->id]));

        // Second import: skip and rename.
        $skip = config_transfer::preview($tsideline, $data, ['rules'], 'skip');
        $this->assertSame([], $skip['plan']['rules']);
        $rename = config_transfer::preview($tsideline, $data, ['rules'], 'rename');
        $this->assertSame('custom_reminder_2', $rename['plan']['rules'][0]['definition']['key']);
    }

    public function test_old_formats_are_migrated_and_foreign_files_rejected(): void {
        $this->resetAfterTest();
        $old = config_transfer::migrate(['format' => config_transfer::FORMAT, 'version' => 1, 'settings' => ['persona' => 'mentor'],
            'scope' => []]);
        $this->assertSame(2, $old['version']);
        $this->assertSame([], $old['rules']);
        $this->expectException(\moodle_exception::class);
        config_transfer::migrate(['format' => 'something_else', 'version' => 1]);
    }
}
