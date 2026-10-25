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
 * Tests for the web services: rights, data subject and the absence of a teacher view (L-16, P-40).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_sideline\external\get_commentary::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_sideline\external\set_consent::class)]
final class external_test extends \advanced_testcase {
    /** @var \stdClass Course. */
    private \stdClass $course;
    /** @var \stdClass Student. */
    private \stdClass $student;
    /** @var \stdClass Teacher. */
    private \stdClass $teacher;
    /** @var \stdClass Sideline activity record. */
    private \stdClass $sideline;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course();
        $this->student = $gen->create_and_enrol($this->course, 'student');
        $this->teacher = $gen->create_and_enrol($this->course, 'editingteacher');
        $this->sideline = $gen->create_module('sideline', ['course' => $this->course->id, 'consentrequired' => 1]);
    }

    public function test_student_gets_the_consent_gate_before_any_commentary(): void {
        $this->setUser($this->student);
        $result = external_api::clean_returnvalue(
            get_commentary::execute_returns(),
            get_commentary::execute($this->sideline->cmid)
        );
        $this->assertSame('consent_required', $result['status']);
        $this->assertSame([], $result['blocks']);
    }

    public function test_student_can_decide_and_then_get_commentary(): void {
        $this->setUser($this->student);
        $decision = set_consent::execute($this->sideline->cmid, true);
        $this->assertSame('accepted', $decision['status']);
        $result = external_api::clean_returnvalue(
            get_commentary::execute_returns(),
            get_commentary::execute($this->sideline->cmid)
        );
        $this->assertSame('ok', $result['status']);
        $this->assertNotEmpty($result['blocks']);
    }

    public function test_the_service_answers_only_for_the_calling_user(): void {
        global $DB;
        $this->setUser($this->student);
        set_consent::execute($this->sideline->cmid, true);
        get_commentary::execute($this->sideline->cmid);

        // A teacher asking gets the teacher's own commentary and consent state, never the student's.
        $this->setUser($this->teacher);
        $result = get_commentary::execute($this->sideline->cmid);
        $this->assertSame('consent_required', $result['status']);
        $this->assertSame(0, $DB->count_records('sideline_cache', ['userid' => $this->teacher->id]));
    }

    public function test_the_parameters_offer_no_way_to_name_another_user(): void {
        $names = array_keys(get_commentary::execute_parameters()->keys);
        $this->assertSame(['cmid'], $names);
        $names = array_keys(set_consent::execute_parameters()->keys);
        $this->assertSame(['cmid', 'accept'], $names);
    }

    public function test_no_capability_lets_teachers_read_learner_data(): void {
        global $DB;
        $caps = array_keys($DB->get_records('capabilities', ['component' => 'mod_sideline'], '', 'name'));
        sort($caps);
        // The manage capability configures questions, rules and transfers; useai and managepersonas grant no reading either.
        $this->assertSame(['mod/sideline:addinstance', 'mod/sideline:manage', 'mod/sideline:managepersonas',
            'mod/sideline:receivegroupstatements', 'mod/sideline:useai', 'mod/sideline:view'], $caps);

        // No registered external function takes a user id: every one of them acts for the caller.
        $functions = $DB->get_records_select('external_functions', "name LIKE 'mod_sideline_%'");
        $this->assertCount(7, $functions);
        foreach ($functions as $function) {
            $params = array_keys(($function->classname . '::execute_parameters')()->keys);
            $this->assertNotContains('userid', $params, $function->name);
        }
    }

    public function test_user_without_access_to_the_course_is_refused(): void {
        $outsider = $this->getDataGenerator()->create_user();
        $this->setUser($outsider);
        $this->expectException(\require_login_exception::class);
        get_commentary::execute($this->sideline->cmid);
    }

    public function test_missing_view_capability_is_refused(): void {
        $roleid = $this->getDataGenerator()->create_role();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $this->course->id, $roleid);
        assign_capability('mod/sideline:view', CAP_PROHIBIT, $roleid, \context_system::instance()->id, true);
        $this->setUser($user);
        // Moodle hides an activity from users without its view capability, so access is already
        // refused while validating the context. The explicit require_capability() is the second line.
        $this->expectException(\require_login_exception::class);
        get_commentary::execute($this->sideline->cmid);
    }

    public function test_an_activity_of_another_type_cannot_be_addressed(): void {
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $this->setUser($this->student);
        $this->expectException(\moodle_exception::class);
        get_commentary::execute($page->cmid);
    }
}
