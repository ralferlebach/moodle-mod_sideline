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

namespace mod_sideline\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use mod_sideline\local\consent\consent_manager;

defined('MOODLE_INTERNAL') || die();

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_sideline\privacy\provider::class)]
/**
 * Privacy API tests: export, deletion for a user, for users and for a whole context (P-48).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_sideline\privacy\provider
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    /** @var \stdClass Course. */
    private \stdClass $course;
    /** @var \stdClass[] Two learners. */
    private array $users = [];
    /** @var \stdClass Sideline instance. */
    private \stdClass $sideline;
    /** @var \context_module Its context. */
    private \context_module $context;

    #[\Override]
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course();
        $this->users = [$gen->create_and_enrol($this->course, 'student'), $gen->create_and_enrol($this->course, 'student')];
        $this->sideline = $gen->create_module('sideline', ['course' => $this->course->id, 'consentrequired' => 1]);
        $this->context = \context_module::instance($this->sideline->cmid);
        $record = $DB->get_record('sideline', ['id' => $this->sideline->id]);
        foreach ($this->users as $user) {
            consent_manager::record_decision($record, (int) $user->id, true, $this->context->id, time());
            $DB->insert_record('sideline_cache', (object) [
                'sidelineid' => $this->sideline->id, 'userid' => $user->id, 'fingerprint' => 'f', 'source' => 'template',
                'commentary' => json_encode(['blocks' => [['key' => 'k', 'text' => 'Hello ' . $user->id]]]),
                'dirty' => 0, 'nexttransition' => 0, 'timecreated' => time(), 'timemodified' => time(),
            ]);
        }
    }

    public function test_metadata_describes_every_table_and_the_ai_link(): void {
        $collection = provider::get_metadata(new collection('mod_sideline'));
        $names = array_map(static fn($item) => $item->get_name(), $collection->get_collection());
        $this->assertContains('sideline_cache', $names);
        $this->assertContains('sideline_consent', $names);
        $this->assertContains('sideline_consent_log', $names);
        $this->assertContains('core_ai', $names);
    }

    public function test_contexts_for_user_contain_the_activity(): void {
        $list = provider::get_contexts_for_userid($this->users[0]->id);
        $this->assertEquals([$this->context->id], $list->get_contextids());
        $this->assertSame([], provider::get_contexts_for_userid(get_admin()->id)->get_contextids());
    }

    public function test_export_contains_commentary_and_consent_history_of_that_user_only(): void {
        $this->export_context_data_for_user($this->users[0]->id, $this->context, 'mod_sideline');
        $writer = writer::with_context($this->context);
        $this->assertTrue($writer->has_any_data());
        $commentary = $writer->get_data([get_string('privacy:path:sideline_cache', 'mod_sideline')]);
        $this->assertStringContainsString('Hello ' . $this->users[0]->id, json_encode($commentary));
        $this->assertStringNotContainsString('Hello ' . $this->users[1]->id, json_encode($commentary));
        $consent = $writer->get_data([get_string('privacy:path:sideline_consent', 'mod_sideline')]);
        $this->assertSame('accepted', $consent->items[0]['status']);
    }

    public function test_delete_for_one_user_keeps_the_other(): void {
        global $DB;
        $list = new approved_contextlist($this->users[0], 'mod_sideline', [$this->context->id]);
        provider::delete_data_for_user($list);
        foreach (['sideline_cache', 'sideline_consent', 'sideline_consent_log'] as $table) {
            $this->assertSame(0, $DB->count_records($table, ['userid' => $this->users[0]->id]), $table);
        }
        foreach (['sideline_consent', 'sideline_consent_log'] as $table) {
            $this->assertGreaterThan(0, $DB->count_records($table, ['userid' => $this->users[1]->id]), $table);
        }
        // Cached texts of others may mention the deleted user: they are derived data and all dropped (issue #14).
        $this->assertSame(0, $DB->count_records('sideline_cache', ['userid' => $this->users[1]->id]));
    }

    public function test_users_in_context_and_delete_for_users(): void {
        global $DB;
        $userlist = new userlist($this->context, 'mod_sideline');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing([$this->users[0]->id, $this->users[1]->id], $userlist->get_userids());

        provider::delete_data_for_users(new approved_userlist($this->context, 'mod_sideline', [$this->users[1]->id]));
        $this->assertSame(0, $DB->count_records('sideline_consent', ['userid' => $this->users[1]->id]));
        $this->assertSame(1, $DB->count_records('sideline_consent', ['userid' => $this->users[0]->id]));
        $this->assertSame(
            0,
            $DB->count_records('sideline_cache', ['sidelineid' => $this->sideline->id]),
            'Cached texts are derived data: all of the instance are dropped (issue #14)'
        );
    }

    public function test_deletion_after_switching_group_mode_off_still_drops_texts_naming_the_user(): void {
        global $DB;
        // The user published while group mode was on; then the mode was switched off and the rows are gone.
        $DB->set_field('sideline', 'sharingmode', 'individual', ['id' => $this->sideline->id]);
        $DB->delete_records('sideline_publish', ['sidelineid' => $this->sideline->id]);
        $this->assertGreaterThan(0, $DB->count_records('sideline_cache', ['sidelineid' => $this->sideline->id]));
        provider::delete_data_for_users(new approved_userlist($this->context, 'mod_sideline', [$this->users[1]->id]));
        $this->assertSame(0, $DB->count_records('sideline_cache', ['sidelineid' => $this->sideline->id]));
    }

    public function test_delete_for_all_users_in_context(): void {
        global $DB;
        provider::delete_data_for_all_users_in_context($this->context);
        foreach (['sideline_cache', 'sideline_consent', 'sideline_consent_log'] as $table) {
            $this->assertSame(0, $DB->count_records($table), $table);
        }
        // The instance itself and its configuration stay.
        $this->assertSame(1, $DB->count_records('sideline'));
    }

    public function test_teachers_who_authorised_sources_and_authors_of_personas_are_covered(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $teacher = $gen->create_and_enrol($this->course, 'editingteacher');
        $further = $gen->create_course();
        $DB->insert_record('sideline_source', (object) ['sidelineid' => $this->sideline->id, 'courseid' => $further->id,
            'usermodified' => $teacher->id]);
        $this->setAdminUser();
        $gen->get_plugin_generator('mod_sideline')->create_persona(['shortname' => 'chess']);
        $admin = get_admin();

        $this->assertEquals([$this->context->id], provider::get_contexts_for_userid($teacher->id)->get_contextids());
        $this->assertContains((int) \context_system::instance()->id, array_map(
            'intval',
            provider::get_contexts_for_userid($admin->id)->get_contextids()
        ));
        $userlist = new userlist($this->context, 'mod_sideline');
        provider::get_users_in_context($userlist);
        $this->assertContains((int) $teacher->id, array_map('intval', $userlist->get_userids()));

        provider::export_user_data(new approved_contextlist($teacher, 'mod_sideline', [$this->context->id]));
        $exported = writer::with_context($this->context)->get_data([get_string('privacy:path:sideline_source', 'mod_sideline')]);
        $this->assertEquals([['course' => (int) $further->id]], $exported->items);

        // Deleting the teacher's data leaves the source waiting for a new confirmation, never evaluated without one.
        provider::delete_data_for_user(new approved_contextlist($teacher, 'mod_sideline', [$this->context->id]));
        $this->assertSame(0, (int) $DB->get_field('sideline_source', 'usermodified', ['courseid' => $further->id]));
        $this->assertSame(1, \mod_sideline\local\instance_config::count_unconfirmed_sources((int) $this->sideline->id));

        provider::delete_data_for_user(new approved_contextlist($admin, 'mod_sideline', [\context_system::instance()->id]));
        $this->assertFalse($DB->record_exists_select(
            'sideline_persona',
            'usermodified = ? OR approvedby = ?',
            [$admin->id, $admin->id]
        ));
        $this->assertTrue($DB->record_exists('sideline_persona', ['shortname' => 'chess']), 'The persona itself stays');
    }
}
