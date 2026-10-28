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

use mod_sideline\local\model\recommendation;
use mod_sideline\local\rules\rule_store;

/**
 * Template versions and variable schemas (P-07).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_sideline\local\service\template_registry
 * @covers \mod_sideline\local\service\message_composer
 * @covers \mod_sideline\local\rules\rule_store
 */
#[\PHPUnit\Framework\Attributes\CoversClass(template_registry::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(message_composer::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(rule_store::class)]
final class template_registry_test extends \advanced_testcase {
    /**
     * Strings of the plugin in a language.
     *
     * @param string $lang Language code.
     * @return array
     */
    private static function strings(string $lang): array {
        $string = [];
        include(\core_component::get_component_directory('mod_sideline') . '/lang/' . $lang . '/sideline.php');
        return $string;
    }

    /**
     * Shipped languages.
     *
     * @return array
     */
    public static function languages(): array {
        return ['en' => ['en'], 'de' => ['de']];
    }

    /**
     * Every template has a schema and uses only declared variables; every schema has a template.
     *
     * @dataProvider languages
     * @param string $lang Language code.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('languages')]
    public function test_templates_and_schemas_match(string $lang): void {
        $strings = self::strings($lang);
        $templates = array_filter($strings, static fn($k) => str_starts_with($k, 'tpl_'), ARRAY_FILTER_USE_KEY);
        foreach ($templates as $id => $text) {
            $key = substr($id, 4);
            $this->assertTrue(template_registry::exists($key), "No schema for $id");
            $declared = array_keys(template_registry::TEMPLATES[$key]['vars']);
            $this->assertSame([], array_diff(template_registry::used_vars($text), $declared), "Undeclared variable in $lang $id");
            $this->assertGreaterThanOrEqual(1, template_registry::TEMPLATES[$key]['version']);
        }
        foreach (array_keys(template_registry::TEMPLATES) as $key) {
            $this->assertArrayHasKey('tpl_' . $key, $strings, "No $lang template for $key");
        }
        foreach (template_registry::TEMPLATES as $schema) {
            foreach ($schema['vars'] as $type) {
                $this->assertContains($type, [template_registry::TEXT, template_registry::INT, template_registry::DATE,
                    template_registry::NAMES]);
            }
        }
    }

    public function test_commentary_records_the_template_version_of_each_block_but_never_delivers_it(): void {
        $this->resetAfterTest();
        $composer = new message_composer();
        $commentary = $composer->compose([
            new recommendation('progress', recommendation::CAT_ORIENTATION, 1, 'none', null, ['done' => 1, 'total' => 2]),
            new recommendation('custom_x', recommendation::CAT_NEXT, 1, 'none', null, [
                'templates' => ['en' => 'Keep going.'], 'templateversion' => 4,
            ]),
        ], [], 'mentor', 'en');
        $this->assertSame(
            ['progress' => 'builtin:progress@1', 'custom_x' => 'custom:custom_x@4'],
            $commentary['meta']['templates']
        );
        $this->assertSame(['generic' => 'builtin:generic@1'], $composer->compose([], [], 'mentor', 'en')['meta']['templates']);

        // Delivered commentary carries no meta data.
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $instance = $this->getDataGenerator()->create_module('sideline', ['course' => $course->id]);
        $this->setUser($user);
        $result = (new commentary_service())->get(
            $instance,
            $course,
            $user,
            \context_module::instance($instance->cmid),
            time()
        );
        $this->assertArrayNotHasKey('meta', $result['commentary']);
    }

    public function test_custom_rule_template_version_rises_only_when_the_wording_changes(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('enablecompletion', 1);
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $instance = $this->getDataGenerator()->create_module('sideline', ['course' => $course->id]);
        $sideline = $DB->get_record('sideline', ['id' => $instance->id]);
        $rule = [
            'key' => 'custom_hint', 'category' => 'next', 'priority' => 10, 'match' => 'all', 'action' => 'none',
            'conditions' => [['type' => 'activity', 'cmid' => 0, 'field' => 'completion', 'is' => 'no']],
            'templates' => ['en' => 'Keep going.'],
        ];
        $id = rule_store::save($sideline, $rule);
        $version = static fn() => rule_store::definitions((int) $sideline->id)[0]['templateversion'];
        $this->assertSame(1, $version());

        rule_store::save($sideline, ['priority' => 20] + $rule, $id);
        $this->assertSame(1, $version(), 'Other changes keep the template version');

        rule_store::save($sideline, ['templates' => ['en' => 'Keep it up.']] + $rule, $id);
        $this->assertSame(2, $version());

        // The version reaches the recommendation and so the commentary meta data and the trace.
        set_config('tracing', 1, 'mod_sideline');
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($user);
        (new commentary_service())->get(
            $DB->get_record('sideline', ['id' => $instance->id]),
            $course,
            $user,
            \context_module::instance($instance->cmid),
            time()
        );
        $trace = json_decode($DB->get_field('sideline_trace', 'trace', ['sidelineid' => $instance->id]), true);
        $this->assertSame('custom:custom_hint@2', $trace['templates']['custom_hint']);
        $this->assertSame(template_registry::VERSION, $trace['versions']['templates']);
        $this->assertSame(\mod_sideline\local\ai\prompt_builder::VERSION, $trace['versions']['prompt']);
        $this->assertSame('not_allowed', $trace['aistatus']);
    }
}
