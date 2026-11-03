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
use mod_sideline\local\service\commentary_service;
use mod_sideline\tests\fake_text_generator;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/sideline/tests/fixtures/fake_text_generator.php');
require_once($CFG->dirroot . '/mod/sideline/tests/fixtures/commentary_course.php');

#[\PHPUnit\Framework\Attributes\CoversClass(claim_checker::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(claim_patterns::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(ai_gateway::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_sideline\local\service\message_composer::class)]
/**
 * Language matrix: invented facts are recognised in every supported language (issue #13), sources and
 * fallbacks are labelled correctly (issue #17).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_sideline\local\ai\claim_checker
 * @covers \mod_sideline\local\ai\claim_patterns
 * @covers \mod_sideline\local\ai\ai_gateway
 * @covers \mod_sideline\local\service\message_composer
 */
final class language_matrix_test extends \advanced_testcase {
    use \mod_sideline\tests\commentary_course;

    /**
     * Invented facts without any number, per language: a pass that did not happen, an unlocking that did not
     * happen, a course completion that did not happen.
     *
     * @return array
     */
    public static function invented_provider(): array {
        return [
            'fr pass' => ['fr', 'Bravo, tu as réussi [anonmod1] !', claim_checker::PASS],
            'fr unlocked' => ['fr', '[anonmod1] est maintenant disponible pour toi.', claim_checker::UNLOCKED],
            'fr course' => ['fr', 'Tu as terminé tout le cours, félicitations.', claim_checker::COURSECOMPLETE],
            'es pass' => ['es', 'Muy bien, has aprobado [anonmod1].', claim_checker::PASS],
            'es unlocked' => ['es', '[anonmod1] ya está disponible para ti.', claim_checker::UNLOCKED],
            'es retry' => ['es', 'Puedes volver a intentar [anonmod1].', claim_checker::RETRY],
            'it pass' => ['it', 'Ottimo, hai superato [anonmod1].', claim_checker::PASS],
            'it unlocked' => ['it', '[anonmod1] è ora disponibile.', claim_checker::UNLOCKED],
            'it grade' => ['it', 'Il tuo voto in [anonmod1] è arrivato.', claim_checker::GRADE],
            'nl pass' => ['nl', 'Goed gedaan, je bent geslaagd voor [anonmod1].', claim_checker::PASS],
            'nl unlocked' => ['nl', '[anonmod1] is nu beschikbaar.', claim_checker::UNLOCKED],
            'nl deadline' => ['nl', 'De deadline van [anonmod1] nadert.', claim_checker::DEADLINE],
            'pt pass' => ['pt', 'Parabéns, você foi aprovado em [anonmod1].', claim_checker::PASS],
            'pt unlocked' => ['pt', '[anonmod1] já está disponível.', claim_checker::UNLOCKED],
            'pt fail' => ['pt', 'Você reprovou em [anonmod1].', claim_checker::FAIL],
        ];
    }

    /**
     * Test with data provider.
     *
     * @dataProvider invented_provider
     * @param string $lang Language.
     * @param string $text Text with an invented fact.
     * @param string $claim The claim that must be found.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invented_provider')]
    public function test_invented_facts_are_recognised_in_every_language(string $lang, string $text, string $claim): void {
        $this->assertTrue(claim_checker::supports($lang));
        $this->assertContains($claim, claim_checker::claims($text, $lang));
        // A plain orientation message must not make that claim.
        $rec = new recommendation('progress', recommendation::CAT_ORIENTATION, 1, recommendation::ACTION_NONE, null, []);
        $this->assertNotNull(claim_checker::check_all(['progress' => $text], [$rec]));
    }

    public function test_a_mixed_language_answer_is_checked_in_every_language(): void {
        $rec = new recommendation('progress', recommendation::CAT_ORIENTATION, 1, recommendation::ACTION_NONE, null, []);
        $this->assertSame('progress:pass', claim_checker::check_all(
            ['progress' => 'Keep going! Hai superato [anonmod1].'],
            [$rec]
        ));
    }

    public function test_every_language_of_the_detector_can_be_checked(): void {
        foreach (['en', 'de', 'fr', 'es', 'it', 'nl', 'pt'] as $lang) {
            $this->assertTrue(ai_gateway::language_supported($lang), $lang);
        }
        $this->assertFalse(ai_gateway::language_supported('pl'), 'No phrase lists, no AI: template instead');
    }

    /**
     * Ask as the learner in a language.
     *
     * @param string $lang Language.
     * @param fake_text_generator $fake Provider double.
     * @return array
     */
    private function ask_in(string $lang, fake_text_generator $fake): array {
        global $DB, $SESSION;
        $this->create_commentary_course();
        set_config('aienabled', 1, 'mod_sideline');
        [$instance, , $context] = $this->sideline(['aienabled' => 1]);
        $this->setUser($this->student);
        $SESSION->lang = $lang;
        force_current_language($lang);
        $record = $DB->get_record('sideline', ['id' => $instance->id]);
        $result = (new commentary_service(new ai_gateway($fake)))->get($record, $this->course, $this->student, $context, time());
        force_current_language('en');
        return $result;
    }

    public function test_a_failed_translation_is_never_stored_or_shown_as_ai(): void {
        global $DB;
        $fake = new fake_text_generator();
        // English answer for a French learner, and the translation comes back with an invented pass.
        $fake->responder = static function (string $prompt): string {
            $blocks = fake_text_generator::translation_blocks($prompt);
            if ($blocks !== null) {
                return json_encode(['blocks' => array_map(
                    static fn($b) => ['key' => $b['key'],
                    'text' => 'Bravo, tu as réussi ' . (preg_match('/\[anon\w+\]/', $b['text'], $m) ? $m[0] : '') . ' !'],
                    $blocks
                )]);
            }
            return fake_text_generator::echoing_tokens()($prompt);
        };
        $result = $this->ask_in('fr', $fake);
        $this->assertSame(2, $fake->calls);
        $this->assertSame('template', $result['commentary']['source'], 'Never presented as AI or as translated');
        $this->assertSame('template', $DB->get_field('sideline_cache', 'source', []));
    }

    public function test_a_language_without_a_pack_is_labelled_as_fallback_never_as_translated(): void {
        $result = $this->ask_in('pl', new fake_text_generator());
        $this->assertSame('template', $result['commentary']['source']);
        $this->assertSame('en', $result['commentary']['locale'], 'Shown in English, the fallback language');
    }

    /**
     * Install a minimal language pack, as the CI sites have only English.
     *
     * @param string $lang Language code.
     * @param string $parent Parent language, empty for none.
     */
    private function install_language(string $lang, string $parent = ''): void {
        global $CFG;
        $dir = $CFG->dataroot . '/lang/' . $lang;
        check_dir_exists($dir);
        file_put_contents($dir . '/langconfig.php', "<?php\n\$string['thislanguage'] = '$lang';\n"
            . "\$string['parentlanguage'] = '$parent';\n\$string['strftimedatetimeshort'] = '%d.%m.%Y %H:%M';\n");
        get_string_manager()->reset_caches(true);
    }

    public function test_an_installed_pack_without_texts_of_the_plugin_is_labelled_as_fallback(): void {
        global $CFG;
        // A pack from the Moodle language server: installed, but without this plugin.
        $this->install_language('pl');
        $result = $this->ask_in('pl', new fake_text_generator());
        $this->assertSame('template', $result['commentary']['source']);
        $this->assertSame('en', $result['commentary']['locale'], 'English text, labelled as English');
        $this->assertStringContainsString('You have completed', $result['commentary']['blocks'][0]['text']);

        // A local customisation in that language makes it the language of the text.
        check_dir_exists($CFG->langlocalroot . '/pl_local');
        file_put_contents($CFG->langlocalroot . '/pl_local/sideline.php', "<?php\n\$string['tpl_progress'] = 'Ukończono "
            . "{\$a->done} z {\$a->total}.';\n");
        get_string_manager()->reset_caches(true);
        $this->assertSame('pl', \mod_sideline\local\service\message_composer::text_language('pl'));
    }

    public function test_a_child_language_takes_the_texts_of_its_parent_without_a_fallback_label(): void {
        $this->install_language('de');
        $this->install_language('de_du', 'de');
        $this->assertSame('de', \mod_sideline\local\service\message_composer::text_language('de_du'));
        $this->assertSame('de', \mod_sideline\local\service\message_composer::text_language('de'));
        $this->assertSame('en', \mod_sideline\local\service\message_composer::text_language('en_us'));
        $silent = new fake_text_generator();
        $silent->responder = static fn(): string => 'no answer';
        $result = $this->ask_in('de_du', $silent);
        $this->assertStringContainsString('Du hast bisher', $result['commentary']['blocks'][0]['text']);
        $this->assertSame('de', language_detector::base_language($result['commentary']['locale']));
    }

    public function test_switching_the_language_at_runtime_changes_text_and_cache(): void {
        global $DB;
        $this->install_language('de');
        $this->create_commentary_course();
        [$instance, , $context] = $this->sideline();
        $this->setUser($this->student);
        $record = $DB->get_record('sideline', ['id' => $instance->id]);
        $service = new commentary_service(new ai_gateway(new fake_text_generator()));
        $en = $service->get($record, $this->course, $this->student, $context, time());
        force_current_language('de');
        $de = $service->get($record, $this->course, $this->student, $context, time());
        force_current_language('en');
        $this->assertSame('en', $en['commentary']['locale']);
        $this->assertSame('de', $de['commentary']['locale']);
        $this->assertNotSame($en['commentary']['blocks'][0]['text'], $de['commentary']['blocks'][0]['text']);
        $this->assertSame(2, $DB->count_records('sideline_cache') + 1, 'One row per user, replaced on the switch');
    }
}
