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

namespace mod_sideline;

use mod_sideline\local\ai\ai_gateway;
use mod_sideline\local\consent\consent_manager;
use mod_sideline\local\dialog\dialog_manager;
use mod_sideline\local\group\sharing_manager;
use mod_sideline\local\service\availability_analyser;
use mod_sideline\local\service\commentary_service;
use mod_sideline\local\service\scope_resolver;
use mod_sideline\local\transfer\config_transfer;
use mod_sideline\tests\fake_text_generator;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/fake_text_generator.php');

/**
 * Regression tests for the findings of the independent privacy and access review of 0.2.0.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_sideline\local\group\group_service
 * @covers \mod_sideline\local\instance_config
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_sideline\local\group\group_service::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_sideline\local\instance_config::class)]
final class review_findings_test extends \advanced_testcase {
    /** @var \stdClass Course. */
    private \stdClass $course;
    /** @var \stdClass Group. */
    private \stdClass $group;
    /** @var \stdClass Sharing peer. */
    private \stdClass $peer;
    /** @var \stdClass Page. */
    private \stdClass $page;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('enablecompletion', 1);
        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course(['enablecompletion' => 1]);
        $this->page = $gen->create_module('page', ['course' => $this->course->id, 'name' => 'Unit A',
            'completion' => COMPLETION_TRACKING_MANUAL]);
        $this->group = $gen->create_group(['courseid' => $this->course->id, 'name' => 'Red']);
        $this->peer = $gen->create_and_enrol($this->course, 'student', ['firstname' => 'Paula']);
        $gen->create_group_member(['groupid' => $this->group->id, 'userid' => $this->peer->id]);
        $this->setAdminUser();
        (new \completion_info($this->course))->update_state(
            get_coursemodule_from_instance('page', $this->page->id),
            COMPLETION_COMPLETE,
            $this->peer->id,
            true
        );
    }

    /**
     * Instance.
     *
     * @param array $options Options.
     * @return \stdClass
     */
    private function sideline(array $options): \stdClass {
        global $DB;
        $instance = $this->getDataGenerator()->create_module('sideline', ['course' => $this->course->id] + $options);
        return $DB->get_record('sideline', ['id' => $instance->id]);
    }

    /**
     * Commentary JSON for a user.
     *
     * @param \stdClass $sideline Instance.
     * @param \stdClass $user User.
     * @return string
     */
    private function json(\stdClass $sideline, \stdClass $user): string {
        global $DB;
        $this->setUser($user);
        $cm = get_coursemodule_from_instance('sideline', $sideline->id);
        $result = (new commentary_service(new ai_gateway(new fake_text_generator())))->get(
            $DB->get_record('sideline', ['id' => $sideline->id]),
            $this->course,
            $user,
            \context_module::instance($cm->id),
            time()
        );
        return json_encode($result['commentary']);
    }

    public function test_1_teachers_never_receive_group_statements(): void {
        $sideline = $this->sideline(['sharingmode' => 'open', 'grouppublish' => 'named_with_group']);
        sharing_manager::record($sideline, (int) $this->peer->id, true, true, 1, time());
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'teacher');
        $editing = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->getDataGenerator()->create_group_member(['groupid' => $this->group->id, 'userid' => $editing->id]);
        $this->assertStringNotContainsString('Paula', $this->json($sideline, $teacher));
        $this->assertStringNotContainsString('Paula', $this->json($sideline, $editing), 'Not even as a group member');

        // A learner in no admitted group gets nothing in open mode either; a group member does.
        $outsider = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->assertStringNotContainsString('Paula', $this->json($sideline, $outsider));
        $member = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->getDataGenerator()->create_group_member(['groupid' => $this->group->id, 'userid' => $member->id]);
        $this->assertStringContainsString('Paula', $this->json($sideline, $member));
    }

    public function test_2_import_cannot_widen_sharing_or_switch_on_the_gate_silently(): void {
        global $DB;
        $sideline = $this->sideline(['sharingmode' => 'closed', 'grouppublish' => 'named']);
        sharing_manager::record($sideline, (int) $this->peer->id, true, true, 1, time());
        consent_manager::record_decision($sideline, (int) $this->peer->id, true, 1, time());
        $data = config_transfer::migrate(['format' => config_transfer::FORMAT, 'version' => 2,
            'settings' => ['sharingmode' => 'open', 'grouppublish' => 'named_with_group', 'consentrequired' => 1]]);
        $preview = config_transfer::preview($sideline, $data, ['settings'], 'replace');
        config_transfer::apply($sideline, $preview['plan']);
        $fresh = $DB->get_record('sideline', ['id' => $sideline->id]);
        $this->assertGreaterThan((int) $sideline->groupversion, (int) $fresh->groupversion);
        $this->assertGreaterThan((int) $sideline->consentversion, (int) $fresh->consentversion);
        $this->assertFalse(sharing_manager::status($fresh, (int) $this->peer->id)['publish']);
        $this->assertSame(consent_manager::RECONSENT_REQUIRED, consent_manager::status($fresh, (int) $this->peer->id));
    }

    public function test_3_activities_in_hidden_or_hidden_entirely_sections_never_appear(): void {
        global $DB;
        set_config('allowstealth', 1);
        $course = $this->getDataGenerator()->create_course(['numsections' => 2], ['createsections' => true]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'section' => 1, 'name' => 'Stealthy']);
        $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'section' => 2, 'name' => 'Behind section',
            'availability' => json_encode(['op' => '&', 'showc' => [true], 'c' => [['type' => 'date', 'd' => '>=',
            't' => time() + DAYSECS]]])]);
        $section = get_fast_modinfo($course)->get_section_info(1);
        if (method_exists(\core_courseformat\local\sectionactions::class, 'set_visibility')) {
            \core_courseformat\formatactions::section($course->id)->set_visibility($section, false);
        } else {
            // Moodle 4.5 and 5.0.
            set_section_visible($course->id, $section->section, 0);
        }
        $DB->set_field('course_modules', 'visible', 1, ['course' => $course->id]);
        $DB->set_field('course_sections', 'availability', json_encode(['op' => '&', 'showc' => [false],
            'c' => [['type' => 'date', 'd' => '>=', 't' => time() + DAYSECS]]]), ['course' => $course->id, 'section' => 2]);
        rebuild_course_cache($course->id, true);
        $instance = $this->getDataGenerator()->create_module('sideline', ['course' => $course->id, 'section' => 0]);
        $names = array_map(static fn($cm) => $cm->name, (new scope_resolver())->resolve(
            $DB->get_record('sideline', ['id' => $instance->id]),
            (int) $student->id
        ));
        $this->assertNotContains('Stealthy', $names);
        $this->assertNotContains('Behind section', $names);
    }

    public function test_4_hidden_conditions_are_never_explained(): void {
        $tree = json_encode(['op' => '&', 'showc' => [false, true], 'c' => [
            ['type' => 'date', 'd' => '>=', 't' => time() + DAYSECS], ['type' => 'completion', 'cm' => 1, 'e' => 1]]]);
        $analyser = new availability_analyser();
        $this->assertNull($analyser->earliest_opening($tree, time()));
        $this->assertSame('other', $analyser->lock_reason($tree));
        $or = json_encode(['op' => '|', 'show' => false, 'c' => [['type' => 'date', 'd' => '>=', 't' => time() + DAYSECS]]]);
        $this->assertNull($analyser->earliest_opening($or, time()));
    }

    public function test_5_withdrawing_consent_ends_group_sharing(): void {
        global $DB;
        $sideline = $this->sideline(['sharingmode' => 'closed', 'grouppublish' => 'named', 'consentrequired' => 1]);
        consent_manager::record_decision($sideline, (int) $this->peer->id, true, 1, time());
        sharing_manager::record($sideline, (int) $this->peer->id, true, true, 1, time());
        $member = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->getDataGenerator()->create_group_member(['groupid' => $this->group->id, 'userid' => $member->id]);
        consent_manager::record_decision($sideline, (int) $member->id, true, 1, time());
        $this->assertStringContainsString('Paula', $this->json($sideline, $member));

        consent_manager::record_decision($sideline, (int) $this->peer->id, false, 1, time());
        $this->assertFalse(sharing_manager::status($sideline, (int) $this->peer->id)['publish']);
        $this->assertStringNotContainsString('Paula', $this->json($sideline, $member));

        // Even a leftover opt-in row is ignored without consent.
        $DB->set_field('sideline_publish', 'publish', 1, ['userid' => $this->peer->id]);
        $this->assertStringNotContainsString('Paula', $this->json($sideline, $member));
    }

    public function test_6_switching_the_gate_off_keeps_a_learners_no(): void {
        global $DB;
        $sideline = $this->sideline(['consentrequired' => 1]);
        consent_manager::record_decision($sideline, (int) $this->peer->id, false, 1, time());
        $DB->set_field('sideline', 'consentrequired', 0, ['id' => $sideline->id]);
        $fresh = $DB->get_record('sideline', ['id' => $sideline->id]);
        $this->assertFalse(consent_manager::allows_processing($fresh, (int) $this->peer->id));
        $other = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->assertTrue(consent_manager::allows_processing($fresh, (int) $other->id));
    }

    public function test_7_changing_a_question_voids_its_answers(): void {
        global $DB;
        $sideline = $this->sideline(['dialogenabled' => 1]);
        $qid = dialog_manager::save_question((int) $sideline->id, (object) ['qtype' => 'text', 'prompt' => 'Why?',
            'config' => ['maxlength' => 100], 'triggerkey' => 'any', 'enabled' => 1]);
        dialog_manager::answer($sideline, (int) $this->peer->id, $qid, '2 weeks is too much', false, time());
        dialog_manager::save_question((int) $sideline->id, (object) ['id' => $qid, 'qtype' => 'mc', 'prompt' => 'Why?',
            'config' => ['options' => [['label' => 'A'], ['label' => 'B'], ['label' => 'C', 'effect' => 'pause_ok']]],
            'triggerkey' => 'any', 'enabled' => 1]);
        $this->assertSame(0, $DB->count_records('sideline_answer', ['questionid' => $qid]));
        $this->assertSame([], dialog_manager::effects($sideline, (int) $this->peer->id));
    }

    public function test_8_a_withdrawal_waits_for_a_store_that_holds_the_sharing_lock(): void {
        $sideline = $this->sideline(['sharingmode' => 'closed', 'grouppublish' => 'named']);
        $lock = sharing_manager::lock((int) $sideline->id);
        $this->assertNotFalse($lock);
        $lock->release();
        // The lock is free again for the withdrawal itself.
        $this->assertFalse(sharing_manager::record($sideline, (int) $this->peer->id, false, false, 1, time())['publish']);
    }
}
