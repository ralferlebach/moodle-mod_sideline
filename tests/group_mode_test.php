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

namespace mod_sideline\local\group;

use mod_sideline\local\ai\ai_gateway;
use mod_sideline\local\service\commentary_service;
use mod_sideline\tests\fake_text_generator;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/fake_text_generator.php');

/**
 * Group mode: test matrix 1 to 8 of the concept extension V1.2 (G9).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_sideline\local\group\group_service
 * @covers \mod_sideline\local\group\sharing_manager
 */
#[\PHPUnit\Framework\Attributes\CoversClass(group_service::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(sharing_manager::class)]
final class group_mode_test extends \advanced_testcase {
    /** @var \stdClass Course. */
    private \stdClass $course;
    /** @var \stdClass Recipient. */
    private \stdClass $recipient;
    /** @var \stdClass[] Members of the recipient's group. */
    private array $peers = [];
    /** @var \stdClass[] Members of the other group. */
    private array $others = [];
    /** @var \stdClass Own group. */
    private \stdClass $own;
    /** @var \stdClass Other group. */
    private \stdClass $other;
    /** @var \stdClass Activity A. */
    private \stdClass $pagea;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('enablecompletion', 1);
        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course(['enablecompletion' => 1]);
        $manual = ['completion' => COMPLETION_TRACKING_MANUAL];
        $this->pagea = $gen->create_module('page', ['course' => $this->course->id, 'name' => 'Unit A'] + $manual);
        $gen->create_module('page', ['course' => $this->course->id, 'name' => 'Unit B'] + $manual);
        $this->own = $gen->create_group(['courseid' => $this->course->id, 'name' => 'Red team']);
        $this->other = $gen->create_group(['courseid' => $this->course->id, 'name' => 'Blue team']);
        $this->recipient = $gen->create_and_enrol($this->course, 'student', ['firstname' => 'Rita']);
        $gen->create_group_member(['groupid' => $this->own->id, 'userid' => $this->recipient->id]);
        foreach (['Paul', 'Petra', 'Pia', 'Pit'] as $name) {
            $user = $gen->create_and_enrol($this->course, 'student', ['firstname' => $name]);
            $gen->create_group_member(['groupid' => $this->own->id, 'userid' => $user->id]);
            $this->peers[] = $user;
        }
        foreach (['Olga', 'Otto', 'Ole'] as $name) {
            $user = $gen->create_and_enrol($this->course, 'student', ['firstname' => $name]);
            $gen->create_group_member(['groupid' => $this->other->id, 'userid' => $user->id]);
            $this->others[] = $user;
        }
    }

    /**
     * Create the instance.
     *
     * @param array $options Options.
     * @return array [instance record, context]
     */
    private function sideline(array $options): array {
        global $DB;
        $instance = $this->getDataGenerator()->create_module('sideline', ['course' => $this->course->id] + $options);
        $cm = get_coursemodule_from_instance('sideline', $instance->id);
        return [$DB->get_record('sideline', ['id' => $instance->id]), \context_module::instance($cm->id)];
    }

    /**
     * A user completes activity A.
     *
     * @param \stdClass $user User.
     * @param int $state Completion state.
     */
    private function complete(\stdClass $user, int $state = COMPLETION_COMPLETE): void {
        $this->setAdminUser();
        $cm = get_coursemodule_from_instance('page', $this->pagea->id);
        (new \completion_info($this->course))->update_state($cm, $state, $user->id, true);
    }

    /**
     * Opt in.
     *
     * @param \stdClass $sideline Instance.
     * @param \stdClass $user User.
     * @param bool $named Allow name.
     */
    private function share(\stdClass $sideline, \stdClass $user, bool $named = false): void {
        sharing_manager::record($sideline, (int) $user->id, true, $named, 1, time());
    }

    /**
     * The recipient's commentary.
     *
     * @param \stdClass $sideline Instance.
     * @param \context_module $context Context.
     * @param fake_text_generator|null $fake AI double.
     * @return array
     */
    private function commentary(\stdClass $sideline, \context_module $context, ?fake_text_generator $fake = null): array {
        global $DB;
        $this->setUser($this->recipient);
        $fresh = $DB->get_record('sideline', ['id' => $sideline->id]);
        return (new commentary_service(new ai_gateway($fake ?? new fake_text_generator())))
            ->get($fresh, $this->course, $this->recipient, $context, time())['commentary'];
    }

    /**
     * Keys of the group blocks.
     *
     * @param array $commentary Commentary.
     * @return array key => text
     */
    private function group_blocks(array $commentary): array {
        $result = [];
        foreach ($commentary['blocks'] as $block) {
            if (str_starts_with($block['key'], 'group_')) {
                $result[$block['key']] = $block['text'];
            }
        }
        return $result;
    }

    public function test_1_individual_mode_never_uses_events_of_others(): void {
        [$sideline, $context] = $this->sideline(['sharingmode' => 'individual']);
        foreach ($this->peers as $peer) {
            $this->share($sideline, $peer, true);
            $this->complete($peer);
        }
        $this->assertSame([], $this->group_blocks($this->commentary($sideline, $context)));
    }

    public function test_2_closed_general_counts_sharing_peers_without_names(): void {
        [$sideline, $context] = $this->sideline(['sharingmode' => 'closed', 'grouppublish' => 'general', 'groupminsize' => 3]);
        foreach (array_slice($this->peers, 0, 3) as $peer) {
            $this->share($sideline, $peer, true);
            $this->complete($peer);
        }
        $blocks = $this->group_blocks($this->commentary($sideline, $context));
        $this->assertArrayHasKey('group_together', $blocks);
        $this->assertStringContainsString('3', $blocks['group_together']);
        foreach (['Paul', 'Petra', 'Pia'] as $name) {
            $this->assertStringNotContainsString($name, $blocks['group_together']);
        }
    }

    public function test_2_without_opt_in_people_are_not_counted_and_small_groups_are_suppressed(): void {
        [$sideline, $context] = $this->sideline(['sharingmode' => 'closed', 'grouppublish' => 'general', 'groupminsize' => 3]);
        foreach ($this->peers as $i => $peer) {
            $this->complete($peer);
            if ($i < 2) {
                $this->share($sideline, $peer);
            }
        }
        // Four completed, but only two opted in: fewer than the minimum, so nothing at all.
        $this->assertSame([], $this->group_blocks($this->commentary($sideline, $context)));
    }

    public function test_2_closed_named_only_with_the_persons_own_name_consent(): void {
        [$sideline, $context] = $this->sideline(['sharingmode' => 'closed', 'grouppublish' => 'named', 'groupminsize' => 3]);
        $this->share($sideline, $this->peers[0], true);
        $this->share($sideline, $this->peers[1], false);
        $this->complete($this->peers[0]);
        $this->complete($this->peers[1]);
        $blocks = $this->group_blocks($this->commentary($sideline, $context));
        $this->assertArrayHasKey('group_named', $blocks);
        $this->assertStringContainsString('Paul', $blocks['group_named']);
        $this->assertStringNotContainsString('Petra', $blocks['group_named']);
    }

    public function test_3_open_group_only_names_the_other_public_group_but_no_person(): void {
        [$sideline, $context] = $this->sideline(['sharingmode' => 'open', 'grouppublish' => 'group_only', 'groupminsize' => 3]);
        foreach ($this->others as $other) {
            $this->share($sideline, $other, true);
            $this->complete($other);
        }
        $blocks = $this->group_blocks($this->commentary($sideline, $context));
        $this->assertArrayHasKey('group_only', $blocks);
        $this->assertStringContainsString('Blue team', $blocks['group_only']);
        $this->assertStringNotContainsString('Olga', $blocks['group_only']);
    }

    public function test_3_open_named_with_group(): void {
        [$sideline, $context] = $this->sideline(['sharingmode' => 'open', 'grouppublish' => 'named_with_group']);
        $this->share($sideline, $this->others[0], true);
        $this->complete($this->others[0]);
        $blocks = $this->group_blocks($this->commentary($sideline, $context));
        $this->assertArrayHasKey('group_named_with_group', $blocks);
        $this->assertStringContainsString('Olga', $blocks['group_named_with_group']);
        $this->assertStringContainsString('Blue team', $blocks['group_named_with_group']);
    }

    public function test_3_closed_mode_never_looks_at_other_groups(): void {
        [$sideline, $context] = $this->sideline(['sharingmode' => 'closed', 'grouppublish' => 'named']);
        foreach ($this->others as $other) {
            $this->share($sideline, $other, true);
            $this->complete($other);
        }
        $this->assertSame([], $this->group_blocks($this->commentary($sideline, $context)));
    }

    public function test_3_non_admitted_groups_are_excluded(): void {
        [$sideline, $context] = $this->sideline(['sharingmode' => 'open', 'grouppublish' => 'group_only', 'groupminsize' => 3]);
        \mod_sideline\local\instance_config::save_groups((int) $sideline->id, (int) $this->course->id, [(int) $this->own->id], []);
        foreach ($this->others as $other) {
            $this->share($sideline, $other);
            $this->complete($other);
        }
        $this->assertSame([], $this->group_blocks($this->commentary($sideline, $context)));
    }

    public function test_4_group_change_takes_effect_at_once(): void {
        [$sideline, $context] = $this->sideline(['sharingmode' => 'closed', 'grouppublish' => 'named']);
        $this->share($sideline, $this->peers[0], true);
        $this->complete($this->peers[0]);
        $this->assertArrayHasKey('group_named', $this->group_blocks($this->commentary($sideline, $context)));

        groups_remove_member($this->own->id, $this->peers[0]->id);
        $this->assertSame([], $this->group_blocks($this->commentary($sideline, $context)));
    }

    public function test_4_multiple_groups_and_deleted_groups(): void {
        [$sideline, $context] = $this->sideline(['sharingmode' => 'closed', 'grouppublish' => 'named']);
        // The recipient is in both groups: Blue team members are now own-group members too.
        $this->getDataGenerator()->create_group_member(['groupid' => $this->other->id, 'userid' => $this->recipient->id]);
        $this->share($sideline, $this->others[0], true);
        $this->complete($this->others[0]);
        $this->assertStringContainsString('Olga', implode(' ', $this->group_blocks($this->commentary($sideline, $context))));

        groups_delete_group($this->other->id);
        $this->assertSame([], $this->group_blocks($this->commentary($sideline, $context)));
    }

    public function test_5_invisible_groups_and_suspended_members(): void {
        global $DB;
        [$sideline, $context] = $this->sideline(['sharingmode' => 'closed', 'grouppublish' => 'named']);
        $this->share($sideline, $this->peers[0], true);
        $this->complete($this->peers[0]);

        // Members of an OWN group cannot see each other: no statement.
        $DB->set_field('groups', 'visibility', GROUPS_VISIBILITY_OWN, ['id' => $this->own->id]);
        \cache_helper::purge_by_definition('core', 'groupdata');
        $this->assertSame([], $this->group_blocks($this->commentary($sideline, $context)));

        $DB->set_field('groups', 'visibility', GROUPS_VISIBILITY_ALL, ['id' => $this->own->id]);
        \cache_helper::purge_by_definition('core', 'groupdata');
        // A suspended member is not counted any more.
        $enrol = $DB->get_record('enrol', ['courseid' => $this->course->id, 'enrol' => 'manual']);
        $DB->set_field(
            'user_enrolments',
            'status',
            ENROL_USER_SUSPENDED,
            ['enrolid' => $enrol->id, 'userid' => $this->peers[0]->id],
        );
        $this->assertSame([], $this->group_blocks($this->commentary($sideline, $context)));
    }

    public function test_6_withdrawal_removes_the_person_and_deletes_cached_texts(): void {
        global $DB;
        [$sideline, $context] = $this->sideline(['sharingmode' => 'closed', 'grouppublish' => 'named']);
        $this->share($sideline, $this->peers[0], true);
        $this->complete($this->peers[0]);
        $this->commentary($sideline, $context);
        $this->assertSame(1, $DB->count_records('sideline_cache', ['sidelineid' => $sideline->id]));

        sharing_manager::record($sideline, (int) $this->peers[0]->id, false, false, 1, time());
        $this->assertSame(0, $DB->count_records('sideline_cache', ['sidelineid' => $sideline->id]));
        $this->assertSame([], $this->group_blocks($this->commentary($sideline, $context)));
        $this->assertSame(2, $DB->count_records('sideline_consent_log', ['userid' => $this->peers[0]->id, 'kind' => 'publish']));
    }

    public function test_6_withdrawal_during_generation_stores_and_delivers_no_group_statement(): void {
        global $DB;
        set_config('aienabled', 1, 'mod_sideline');
        [$sideline, $context] = $this->sideline(['sharingmode' => 'closed', 'grouppublish' => 'named', 'aienabled' => 1]);
        $this->share($sideline, $this->peers[0], true);
        $this->complete($this->peers[0]);
        $fake = new fake_text_generator();
        $echo = fake_text_generator::echoing_tokens();
        $peerid = (int) $this->peers[0]->id;
        $fake->responder = function (string $prompt) use ($echo, $DB, $sideline, $peerid) {
            $DB->set_field('sideline_publish', 'publish', 0, ['sidelineid' => $sideline->id, 'userid' => $peerid]);
            return $echo($prompt);
        };
        $commentary = $this->commentary($sideline, $context, $fake);
        $this->assertSame(1, $fake->calls);
        $this->assertStringNotContainsString('Paul', json_encode($commentary));
        $this->assertSame(0, $DB->count_records('sideline_cache', ['sidelineid' => $sideline->id]));
    }

    public function test_6_widening_the_level_requires_a_new_decision(): void {
        global $DB;
        [$sideline, $context] = $this->sideline(['sharingmode' => 'closed', 'grouppublish' => 'general']);
        $this->share($sideline, $this->peers[0], true);
        $cm = get_coursemodule_from_instance('sideline', $sideline->id);
        $data = (object) (array) $sideline;
        $data->instance = $sideline->id;
        $data->coursemodule = $cm->id;
        $data->grouppublish = 'named';
        sideline_update_instance($data);
        $fresh = $DB->get_record('sideline', ['id' => $sideline->id]);
        $status = sharing_manager::status($fresh, (int) $this->peers[0]->id);
        $this->assertFalse($status['publish']);
        $this->assertTrue($status['reconsent']);
    }

    public function test_7_failed_and_old_completions_are_never_published(): void {
        global $DB;
        [$sideline, $context] = $this->sideline(['sharingmode' => 'closed', 'grouppublish' => 'named']);
        $this->share($sideline, $this->peers[0], true);
        $this->share($sideline, $this->peers[1], true);
        $cm = get_coursemodule_from_instance('page', $this->pagea->id);
        // A failed completion, as a graded activity would store it.
        $DB->insert_record('course_modules_completion', (object) [
            'coursemoduleid' => $cm->id, 'userid' => $this->peers[0]->id, 'completionstate' => COMPLETION_COMPLETE_FAIL,
            'overrideby' => null, 'timemodified' => time(),
        ]);
        $this->complete($this->peers[1]);
        $DB->set_field(
            'course_modules_completion',
            'timemodified',
            time() - 30 * DAYSECS,
            ['coursemoduleid' => $cm->id, 'userid' => $this->peers[1]->id]
        );
        $this->assertSame([], $this->group_blocks($this->commentary($sideline, $context)));
    }

    public function test_8_free_text_answers_of_others_never_appear(): void {
        global $DB;
        [$sideline, $context] = $this->sideline(['sharingmode' => 'closed', 'grouppublish' => 'named', 'dialogenabled' => 1]);
        $qid = $this->getDataGenerator()->get_plugin_generator('mod_sideline')->create_question([
            'sidelineid' => $sideline->id, 'qtype' => 'text', 'config' => ['maxlength' => 200],
        ]);
        $fresh = $DB->get_record('sideline', ['id' => $sideline->id]);
        \mod_sideline\local\dialog\dialog_manager::answer($fresh, (int) $this->peers[0]->id, $qid, 'VERY PRIVATE', false, time());
        $this->share($sideline, $this->peers[0], true);
        $this->complete($this->peers[0]);
        $this->assertStringNotContainsString('VERY PRIVATE', json_encode($this->commentary($sideline, $context)));
    }

    public function test_9_ai_sees_only_tokens_for_people_and_groups(): void {
        set_config('aienabled', 1, 'mod_sideline');
        [$sideline, $context] = $this->sideline(['sharingmode' => 'open', 'grouppublish' => 'named_with_group', 'aienabled' => 1]);
        $this->share($sideline, $this->others[0], true);
        $this->complete($this->others[0]);
        $fake = new fake_text_generator();
        $fake->responder = fake_text_generator::echoing_tokens();
        $commentary = $this->commentary($sideline, $context, $fake);
        $prompt = implode("\n", $fake->prompts);
        $this->assertStringNotContainsString('Olga', $prompt);
        $this->assertStringNotContainsString('Blue team', $prompt);
        $this->assertStringContainsString('[anonpeer1]', $prompt);
        // Resolved locally, after validation and the contributor recheck.
        $this->assertSame('ai', $commentary['source']);
        $this->assertStringContainsString('Olga', json_encode($commentary));
    }
}
