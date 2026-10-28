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

namespace mod_sideline\local\persona;

use mod_sideline\local\ai\ai_gateway;
use mod_sideline\local\ai\persona_registry;
use mod_sideline\tests\fake_text_generator;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/fake_text_generator.php');

/**
 * Persona preview with invented data (P-14).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_sideline\local\persona\persona_preview
 * @covers \mod_sideline\local\ai\persona_registry
 * @covers \mod_sideline\local\service\message_composer
 */
#[\PHPUnit\Framework\Attributes\CoversClass(persona_preview::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_sideline\local\ai\persona_registry::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_sideline\local\service\message_composer::class)]
final class persona_preview_test extends \advanced_testcase {
    /** @var int Fixed time. */
    private const NOW = 1800000000;

    public function test_preview_of_a_draft_shows_its_style_and_opener_without_names_in_the_prompt(): void {
        $this->resetAfterTest();
        $id = $this->getDataGenerator()->get_plugin_generator('mod_sideline')->create_persona([
            'shortname' => 'lighthouse', 'style' => 'You are a calm lighthouse keeper. Speak in short images of the sea.',
            'opener' => 'Steady light ahead.', 'status' => 'draft',
        ]);
        $this->assertFalse(persona_registry::is_valid('site_lighthouse'), 'A draft is not usable');

        $preview = persona_preview::for_site_persona($id);
        $prompt = $preview->prompt('en', self::NOW);
        $this->assertStringContainsString('lighthouse keeper', $prompt);
        $this->assertLessThan(strpos($prompt, 'lighthouse keeper'), strpos($prompt, 'RULES'), 'Safety rules come first');
        $this->assertStringContainsString('[anonuser1]', $prompt);
        foreach ([persona_preview::SAMPLE_NAME, get_string('preview_sample_quiz', 'mod_sideline')] as $real) {
            $this->assertStringNotContainsString($real, $prompt);
        }

        $texts = array_values($preview->template_texts('en', self::NOW));
        $this->assertCount(3, $texts);
        $this->assertStringStartsWith('Steady light ahead.', $texts[0]);
    }

    public function test_builtin_preview_and_ai_sample_through_the_normal_checks(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('aienabled', 1, 'mod_sideline');
        $preview = persona_preview::for_builtin('cornercoach', 'en');
        $texts = $preview->template_texts('en', self::NOW);
        $this->assertStringStartsWith(get_string('opener_cornercoach', 'mod_sideline'), $texts['deadline_soon']);

        $fake = new fake_text_generator();
        $this->assertTrue(persona_preview::ai_available($fake, (int) get_admin()->id, self::NOW));
        $fake->responder = fake_text_generator::echoing_tokens();
        $sample = $preview->ai_sample(new ai_gateway($fake), (int) get_admin()->id, 'en', self::NOW);
        $this->assertSame(ai_gateway::STATUS_OK, $sample['status']);
        $this->assertStringContainsString(get_string('preview_sample_essay', 'mod_sideline'), $sample['texts']['deadline_soon']);
        $this->assertStringContainsString('energetic corner coach', $fake->prompts[0]);
        $this->assertSame(['preview'], $DB->get_fieldset_select('sideline_aiusage', 'purpose', '1 = 1'));
        // No learner data is read or written.
        $this->assertSame(0, $DB->count_records('sideline_cache'));
        $this->assertSame(0, $DB->count_records('sideline_trace'));

        // A sample that invents a number is rejected like any learner text.
        $fake->responder = fake_text_generator::echoing_tokens(' in 99 days');
        $sample = $preview->ai_sample(new ai_gateway($fake), (int) get_admin()->id, 'en', self::NOW);
        $this->assertNull($sample['texts']);
        $this->assertSame('invalid:number', $sample['status']);

        set_config('aienabled', 0, 'mod_sideline');
        $this->assertFalse(persona_preview::ai_available($fake, (int) get_admin()->id, self::NOW));
        $fake->available = false;
        set_config('aienabled', 1, 'mod_sideline');
        $this->assertFalse(persona_preview::ai_available($fake, (int) get_admin()->id, self::NOW));
    }

    public function test_unknown_builtin_is_rejected(): void {
        $this->expectException(\invalid_parameter_exception::class);
        persona_preview::for_builtin('nobody', 'en');
    }
}
