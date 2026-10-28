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

namespace mod_sideline\external;

use core_external\external_api;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/webservice/tests/helpers.php');

/**
 * The learner's own write services: persona choice, deleting an answer, group sharing (P-19, P-42, L-G05).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_sideline\external\set_persona
 * @covers \mod_sideline\external\delete_answer
 * @covers \mod_sideline\external\set_group_sharing
 * @covers \mod_sideline\external\get_my_answers
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_sideline\external\set_persona::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_sideline\external\delete_answer::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_sideline\external\set_group_sharing::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_sideline\external\get_my_answers::class)]
final class services_test extends \advanced_testcase {
    /** @var \stdClass Course. */
    private \stdClass $course;
    /** @var \stdClass Learner. */
    private \stdClass $student;

    /**
     * Call a service as the next request would: with a fresh page, so that require_login() may set its context again.
     *
     * @param callable $call The call.
     * @return mixed
     */
    private function request(callable $call): mixed {
        global $PAGE;
        $PAGE = new \moodle_page();
        return $call();
    }

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
    }

    public function test_persona_choice_only_when_offered_and_within_the_offer(): void {
        global $DB;
        $closed = $this->getDataGenerator()->create_module('sideline', ['course' => $this->course->id]);
        $open = $this->getDataGenerator()->create_module('sideline', ['course' => $this->course->id, 'personachoice' => 1,
            'personas' => 'mentor,reporter', 'persona' => 'mentor']);
        $this->setUser($this->student);

        $result = external_api::clean_returnvalue(
            set_persona::execute_returns(),
            $this->request(fn() => set_persona::execute($open->cmid, 'reporter'))
        );
        $this->assertSame('reporter', $result['persona']);
        $this->assertSame('reporter', $DB->get_field('sideline_pref', 'persona', ['userid' => $this->student->id]));
        // Back to the teacher's default.
        $this->assertSame('mentor', $this->request(fn() => set_persona::execute($open->cmid, ''))['persona']);
        $this->assertSame(0, $DB->count_records('sideline_pref'));

        try {
            $this->request(fn() => set_persona::execute($open->cmid, 'cornercoach'));
            $this->fail('A persona outside the offer must be refused');
        } catch (\invalid_parameter_exception $e) {
            $this->assertStringContainsString('not offered', $e->getMessage());
        }
        $this->expectException(\moodle_exception::class);
        $this->request(fn() => set_persona::execute($closed->cmid, 'reporter'));
    }

    public function test_learner_deletes_own_answer_and_lists_only_own_answers(): void {
        global $DB;
        $instance = $this->getDataGenerator()->create_module('sideline', ['course' => $this->course->id, 'dialogenabled' => 1]);
        $sideline = $DB->get_record('sideline', ['id' => $instance->id]);
        $qid = $this->getDataGenerator()->get_plugin_generator('mod_sideline')->create_question(['sidelineid' => $sideline->id]);
        $other = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        \mod_sideline\local\dialog\dialog_manager::answer($sideline, (int) $this->student->id, $qid, 1, false, time());
        \mod_sideline\local\dialog\dialog_manager::answer($sideline, (int) $other->id, $qid, 0, false, time());

        $this->setUser($this->student);
        $answers = external_api::clean_returnvalue(
            get_my_answers::execute_returns(),
            $this->request(fn() => get_my_answers::execute($instance->cmid))
        );
        $this->assertCount(1, $answers['answers']);
        $result = external_api::clean_returnvalue(
            delete_answer::execute_returns(),
            $this->request(fn() => delete_answer::execute($instance->cmid, $qid))
        );
        $this->assertTrue($result['deleted']);
        $this->assertSame(0, $DB->count_records('sideline_answer', ['userid' => $this->student->id]));
        $this->assertSame(1, $DB->count_records('sideline_answer', ['userid' => $other->id]), 'Other answers stay');
    }

    public function test_group_sharing_needs_a_sharing_mode_and_consent(): void {
        $individual = $this->getDataGenerator()->create_module('sideline', ['course' => $this->course->id]);
        $closed = $this->getDataGenerator()->create_module('sideline', ['course' => $this->course->id,
            'sharingmode' => 'closed', 'grouppublish' => 'named']);
        $gated = $this->getDataGenerator()->create_module('sideline', ['course' => $this->course->id,
            'sharingmode' => 'closed', 'consentrequired' => 1]);
        $this->setUser($this->student);

        $result = external_api::clean_returnvalue(
            set_group_sharing::execute_returns(),
            $this->request(fn() => set_group_sharing::execute($closed->cmid, true, true))
        );
        $this->assertSame(['publish' => true, 'named' => true], $result);
        $off = ['publish' => false, 'named' => false];
        $this->assertSame($off, $this->request(fn() => set_group_sharing::execute($closed->cmid, false, true)));
        // Stopping is always possible, even where sharing is not offered.
        $this->assertSame($off, $this->request(fn() => set_group_sharing::execute($individual->cmid, false)));

        foreach ([[$individual, 'err_nosharingmode'], [$gated, 'err_sharingconsent']] as [$instance, $error]) {
            try {
                $this->request(fn() => set_group_sharing::execute($instance->cmid, true));
                $this->fail('Sharing must be refused: ' . $error);
            } catch (\moodle_exception $e) {
                $this->assertSame($error, $e->errorcode);
            }
        }
    }
}
