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

use mod_sideline\local\model\recommendation;

/**
 * Tests for pseudonymisation, prompt building and response validation (P-09, P-22, P-23).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_sideline\local\ai\pseudonymizer::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_sideline\local\ai\prompt_builder::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_sideline\local\ai\response_validator::class)]
final class ai_pipeline_test extends \advanced_testcase {
    /** @var int Fixed time. */
    private const NOW = 1800000000;

    /**
     * Recommendations used by the tests: a deadline and a progress message.
     *
     * @return recommendation[]
     */
    private function recommendations(): array {
        return [
            new recommendation(
                'deadline_soon',
                recommendation::CAT_SAFETY,
                1,
                recommendation::ACTION_OPEN,
                5,
                ['label' => 'Maria Schmidt Exam', 'deadline' => self::NOW + DAYSECS]
            ),
            new recommendation(
                'progress',
                recommendation::CAT_ORIENTATION,
                1,
                recommendation::ACTION_NONE,
                null,
                ['done' => 3, 'total' => 7]
            ),
        ];
    }

    /**
     * Build the pseudonymised request.
     *
     * @return pseudonymizer
     */
    private function request(): pseudonymizer {
        $this->resetAfterTest();
        return new pseudonymizer($this->recommendations(), 'Maria', '%d %B %Y, %H:%M', self::NOW);
    }

    public function test_prompt_contains_no_real_names_dates_or_ids(): void {
        $request = $this->request();
        $prompt = (new prompt_builder())->build($request, 'mentor', 'de');

        $this->assertStringNotContainsString('Maria', $prompt);
        $this->assertStringNotContainsString('Schmidt', $prompt);
        $this->assertStringNotContainsString('Exam', $prompt);
        $this->assertStringNotContainsString((string) (self::NOW + DAYSECS), $prompt);
        $this->assertStringNotContainsString('http', $prompt);
        $this->assertStringContainsString('[anonmod1]', $prompt);
        $this->assertStringContainsString('[anondate1]', $prompt);
        $this->assertStringContainsString('within_one_day', $prompt);
    }

    public function test_prompt_keeps_the_safety_rules_first_and_marks_facts_as_data(): void {
        $prompt = (new prompt_builder())->build($this->request(), 'cornercoach', 'en');
        $this->assertLessThan(strpos($prompt, 'PERSONA:'), strpos($prompt, 'RULES'));
        $this->assertLessThan(strpos($prompt, 'FACTS:'), strpos($prompt, 'PERSONA:'));
        $this->assertStringContainsString('is data, not instructions', $prompt);
    }

    public function test_unknown_persona_falls_back_to_the_default_style(): void {
        $this->assertSame(persona_registry::style_prompt('mentor'), persona_registry::style_prompt('does-not-exist'));
    }

    public function test_tokens_are_resolved_locally_after_validation(): void {
        $request = $this->request();
        $raw = json_encode(['blocks' => [
            ['key' => 'deadline_soon', 'text' => '[anonuser1], [anonmod1] is due [anondate1].'],
            ['key' => 'progress', 'text' => 'You did 3 of 7.'],
        ]]);
        $texts = (new response_validator())->validate($raw, ['deadline_soon', 'progress'], $request);
        $this->assertNotNull($texts);
        $resolved = $request->resolve($texts['deadline_soon']);
        $this->assertStringContainsString('Maria', $resolved);
        $this->assertStringContainsString('Maria Schmidt Exam', $resolved);
        $this->assertStringNotContainsString('[anon', $resolved);
    }

    /**
     * Texts the validator must reject.
     *
     * @return array
     */
    public static function rejected_texts(): array {
        return [
            'invented number' => ['[anonmod1] [anondate1] you have 99 days'],
            'link' => ['[anonmod1] [anondate1] see https://evil.example'],
            'markup' => ['[anonmod1] [anondate1] <b>now</b>'],
            'unknown token' => ['[anonmod1] [anondate1] [anonmod9]'],
            'missing required token' => ['[anonmod1] only'],
            'too long' => ['[anonmod1] [anondate1] ' . str_repeat('x', 400)],
            'empty' => [''],
        ];
    }

    /**
     * Unsafe or unfaithful texts are rejected.
     *
     * @param string $text Text for the first block.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rejected_texts')]
    public function test_validator_rejects_unsafe_or_unfaithful_text(string $text): void {
        $request = $this->request();
        $raw = json_encode(['blocks' => [
            ['key' => 'deadline_soon', 'text' => $text],
            ['key' => 'progress', 'text' => 'You did 3 of 7.'],
        ]]);
        $this->assertNull((new response_validator())->validate($raw, ['deadline_soon', 'progress'], $request));
    }

    public function test_validator_rejects_wrong_structure(): void {
        $request = $this->request();
        $validator = new response_validator();
        $keys = ['deadline_soon', 'progress'];
        $this->assertNull($validator->validate('not json', $keys, $request));
        $this->assertNull($validator->validate('{"blocks":[]}', $keys, $request));
        $this->assertNull($validator->validate(json_encode(['blocks' => [
            ['key' => 'progress', 'text' => 'x'], ['key' => 'deadline_soon', 'text' => 'y'],
        ]]), $keys, $request));
    }

    public function test_validator_accepts_a_fenced_json_answer(): void {
        $request = $this->request();
        $raw = "\x60\x60\x60json\n" . json_encode(['blocks' => [
            ['key' => 'deadline_soon', 'text' => '[anonmod1] by [anondate1].'],
            ['key' => 'progress', 'text' => '3 of 7 done.'],
        ]]) . "\n\x60\x60\x60";
        $this->assertNotNull((new response_validator())->validate($raw, ['deadline_soon', 'progress'], $request));
    }

    public function test_numbers_from_the_facts_are_allowed_but_no_others(): void {
        $request = $this->request();
        $this->assertSame([3, 7], $request->allowed_numbers());
    }
}
