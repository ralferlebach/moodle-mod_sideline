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

use mod_sideline\local\ai\ai_gateway;
use mod_sideline\local\cache\commentary_cache;
use mod_sideline\local\instance_config;
use mod_sideline\tests\fake_text_generator;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/sideline/tests/fixtures/fake_text_generator.php');
require_once($CFG->dirroot . '/mod/sideline/db/upgrade.php');
require_once($CFG->libdir . '/upgradelib.php');

#[\PHPUnit\Framework\Attributes\CoversClass(scope_resolver::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(instance_config::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(commentary_cache::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_sideline\local\observer::class)]
/**
 * Course sources need a current authority (issue #5); course branches, rights and groups invalidate
 * the cache or provably change the next delivery (issue #16).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_sideline\local\service\scope_resolver
 * @covers \mod_sideline\local\instance_config
 * @covers \mod_sideline\local\cache\commentary_cache
 * @covers \mod_sideline\local\observer
 */
final class source_authority_test extends \advanced_testcase {
    /** @var \core_course_category Category of the branch. */
    private \core_course_category $branch;
    /** @var \core_course_category Category outside the branch. */
    private \core_course_category $other;
    /** @var \stdClass The instance's own course. */
    private \stdClass $home;
    /** @var \stdClass Teacher. */
    private \stdClass $teacher;
    /** @var \stdClass Learner. */
    private \stdClass $student;
    /** @var \stdClass Instance record. */
    private \stdClass $sideline;
    /** @var \stdClass Further course in the branch. */
    private \stdClass $further;

    #[\Override]
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        set_config('crosscourse', 1, 'mod_sideline');
        set_config('enablecompletion', 1);
        $gen = $this->getDataGenerator();
        $this->branch = $gen->create_category(['name' => 'Engineering']);
        $this->other = $gen->create_category(['name' => 'Arts']);
        $this->home = $gen->create_course(['category' => $this->branch->id, 'enablecompletion' => 1]);
        $instance = $gen->create_module('sideline', ['course' => $this->home->id]);
        $this->sideline = $DB->get_record('sideline', ['id' => $instance->id]);
        $this->student = $gen->create_and_enrol($this->home, 'student');
        // The authorising teacher may manage activities in the whole branch.
        $this->teacher = $gen->create_and_enrol($this->home, 'editingteacher');
        $branchcontext = \context_coursecat::instance($this->branch->id);
        role_assign((int) $DB->get_field('role', 'id', ['shortname' => 'manager']), $this->teacher->id, $branchcontext->id);
        $this->further = $gen->create_course(['category' => $this->branch->id, 'enablecompletion' => 1]);
        $gen->enrol_user($this->student->id, $this->further->id, 'student');
        $gen->create_module('page', ['course' => $this->further->id, 'name' => 'Remote unit',
            'completion' => COMPLETION_TRACKING_MANUAL, 'completionexpected' => time() + 2 * DAYSECS]);
    }

    /**
     * Names of the observed activities.
     *
     * @return string[]
     */
    private function observed(): array {
        global $DB;
        $fresh = $DB->get_record('sideline', ['id' => $this->sideline->id]);
        $names = array_map(static fn(\cm_info $cm) => $cm->name, (new scope_resolver())->resolve($fresh, (int) $this->student->id));
        sort($names);
        return array_values($names);
    }

    public function test_a_source_without_a_teacher_on_record_is_not_evaluated(): void {
        global $DB;
        $DB->insert_record('sideline_source', (object) ['sidelineid' => $this->sideline->id, 'courseid' => $this->further->id,
            'usermodified' => 0]);
        $DB->insert_record('sideline_sourcecat', (object) ['sidelineid' => $this->sideline->id, 'categoryid' => $this->branch->id,
            'usermodified' => 0]);
        $this->assertSame([], $this->observed());
        $this->assertSame(2, instance_config::count_unconfirmed_sources((int) $this->sideline->id));

        // A teacher who may manage activities there confirms it by saving: from then on it counts.
        instance_config::save_sources($this->sideline, [$this->further->id], (int) $this->teacher->id);
        $this->assertSame(['Remote unit'], $this->observed());
        $this->assertSame(1, instance_config::count_unconfirmed_sources((int) $this->sideline->id));
    }

    public function test_the_upgrade_records_affected_instances_and_drops_their_texts(): void {
        global $DB;
        $DB->insert_record('sideline_source', (object) ['sidelineid' => $this->sideline->id, 'courseid' => $this->further->id,
            'usermodified' => 0]);
        $DB->insert_record('sideline_cache', (object) ['sidelineid' => $this->sideline->id, 'userid' => $this->student->id,
            'fingerprint' => 'x', 'source' => 'template', 'commentary' => '{}', 'dirty' => 0, 'nexttransition' => 0,
            'stamp' => 0, 'timecreated' => 1, 'timemodified' => 1]);
        $before = (int) $this->sideline->configversion;
        mod_sideline_upgrade_report_legacy_sources();
        $this->assertSame($before + 1, (int) $DB->get_field('sideline', 'configversion', ['id' => $this->sideline->id]));
        $this->assertSame(0, $DB->count_records('sideline_cache', ['sidelineid' => $this->sideline->id]));
        $this->assertTrue($DB->record_exists_select(
            'upgrade_log',
            'plugin = ? AND ' . $DB->sql_like('details', '?'),
            ['mod_sideline', '%instance ' . $this->sideline->id . '%']
        ));
    }

    public function test_a_revoked_role_of_the_teacher_stops_old_and_new_sources(): void {
        global $DB;
        instance_config::save_source_categories($this->sideline, [$this->branch->id], (int) $this->teacher->id);
        $this->assertSame(['Remote unit'], $this->observed());
        $DB->insert_record('sideline_cache', (object) ['sidelineid' => $this->sideline->id, 'userid' => $this->student->id,
            'fingerprint' => 'x', 'source' => 'template', 'commentary' => '{}', 'dirty' => 0, 'nexttransition' => 0,
            'stamp' => 0, 'timecreated' => 1, 'timemodified' => 1]);
        $manager = $DB->get_field('role', 'id', ['shortname' => 'manager']);
        role_unassign($manager, $this->teacher->id, \context_coursecat::instance($this->branch->id)->id);
        $this->assertSame([], $this->observed());
        $this->assertEquals(
            1,
            $DB->get_field('sideline_cache', 'dirty', ['sidelineid' => $this->sideline->id]),
            'The role change of the authorising teacher marks the texts dirty'
        );
    }

    public function test_a_deleted_course_is_no_source(): void {
        instance_config::save_sources($this->sideline, [$this->further->id], (int) $this->teacher->id);
        delete_course($this->further, false);
        $this->assertSame([], $this->observed());
    }

    public function test_a_completion_in_a_branch_course_marks_the_instance_dirty(): void {
        global $DB;
        instance_config::save_source_categories($this->sideline, [$this->branch->id], (int) $this->teacher->id);
        $DB->insert_record('sideline_cache', (object) ['sidelineid' => $this->sideline->id, 'userid' => $this->student->id,
            'fingerprint' => 'x', 'source' => 'template', 'commentary' => '{}', 'dirty' => 0, 'nexttransition' => 0,
            'stamp' => 0, 'timecreated' => 1, 'timemodified' => 1]);
        commentary_cache::mark_user_dirty((int) $this->further->id, (int) $this->student->id);
        $this->assertEquals(1, $DB->get_field('sideline_cache', 'dirty', ['sidelineid' => $this->sideline->id]));
    }

    public function test_moving_a_course_out_of_the_branch_changes_the_next_delivery(): void {
        global $DB;
        instance_config::save_source_categories($this->sideline, [$this->branch->id], (int) $this->teacher->id);
        $cm = get_coursemodule_from_instance('sideline', $this->sideline->id);
        $context = \context_module::instance($cm->id);
        $service = new commentary_service(new ai_gateway(new fake_text_generator()));
        $this->setUser($this->student);
        $first = $service->get(
            $DB->get_record('sideline', ['id' => $this->sideline->id]),
            $this->home,
            $this->student,
            $context,
            time()
        );
        $this->assertStringContainsString('Remote unit', json_encode($first));

        // The move happens without any of the plugin's events reaching the cache row (worst case).
        $DB->set_field('course', 'category', $this->other->id, ['id' => $this->further->id]);
        \core_course_category::role_assignment_changed(0, \context_system::instance());
        \cache_helper::purge_all();
        $second = $service->get(
            $DB->get_record('sideline', ['id' => $this->sideline->id]),
            $this->home,
            $this->student,
            $context,
            time()
        );
        $this->assertStringNotContainsString('Remote unit', json_encode($second), 'The fingerprint follows the state');
    }

    public function test_category_events_mark_instances_with_branches_dirty(): void {
        global $DB;
        instance_config::save_source_categories($this->sideline, [$this->branch->id], (int) $this->teacher->id);
        $DB->insert_record('sideline_cache', (object) ['sidelineid' => $this->sideline->id, 'userid' => $this->student->id,
            'fingerprint' => 'x', 'source' => 'template', 'commentary' => '{}', 'dirty' => 0, 'nexttransition' => 0,
            'stamp' => 0, 'timecreated' => 1, 'timemodified' => 1]);
        $this->branch->update(['name' => 'Engineering and Science']);
        $this->assertEquals(1, $DB->get_field('sideline_cache', 'dirty', ['sidelineid' => $this->sideline->id]));

        $DB->set_field('sideline_cache', 'dirty', 0, []);
        update_course((object) ['id' => $this->further->id, 'category' => $this->other->id]);
        $this->assertEquals(
            1,
            $DB->get_field('sideline_cache', 'dirty', ['sidelineid' => $this->sideline->id]),
            'A course leaving a branch marks the branch instances dirty'
        );

        // An instance without any branch or source is not touched (no site-wide clearing).
        $plain = $this->getDataGenerator()->create_module('sideline', ['course' => $this->getDataGenerator()->create_course()->id]);
        $DB->insert_record('sideline_cache', (object) ['sidelineid' => $plain->id, 'userid' => $this->student->id,
            'fingerprint' => 'x', 'source' => 'template', 'commentary' => '{}', 'dirty' => 0, 'nexttransition' => 0,
            'stamp' => 0, 'timecreated' => 1, 'timemodified' => 1]);
        update_course((object) ['id' => $this->further->id, 'category' => $this->branch->id]);
        $this->assertEquals(0, $DB->get_field('sideline_cache', 'dirty', ['sidelineid' => $plain->id]));
    }
}
