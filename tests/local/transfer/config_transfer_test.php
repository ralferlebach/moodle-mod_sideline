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

namespace mod_sideline\local\transfer;

use mod_sideline\local\instance_config;

#[\PHPUnit\Framework\Attributes\CoversClass(config_transfer::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(instance_config::class)]
/**
 * Complete and safe configuration transfer (issue #10) and the import as a permission and consent
 * boundary (issue #18).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_sideline\local\transfer\config_transfer
 * @covers \mod_sideline\local\instance_config
 */
final class config_transfer_test extends \advanced_testcase {
    /** @var \stdClass Source course. */
    private \stdClass $source;
    /** @var \stdClass Target course. */
    private \stdClass $target;
    /** @var \stdClass Teacher of both courses. */
    private \stdClass $teacher;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('crosscourse', 1, 'mod_sideline');
        $gen = $this->getDataGenerator();
        $this->source = $gen->create_course(['shortname' => 'SRC']);
        $this->target = $gen->create_course(['shortname' => 'TGT']);
        $this->teacher = $gen->create_and_enrol($this->source, 'editingteacher');
        $gen->enrol_user($this->teacher->id, $this->target->id, 'editingteacher');
        $this->setUser($this->teacher);
    }

    /**
     * An instance record.
     *
     * @param \stdClass $course Course.
     * @param array $options Options.
     * @return \stdClass
     */
    private function coach(\stdClass $course, array $options = []): \stdClass {
        global $DB;
        $instance = $this->getDataGenerator()->create_module('sideline', ['course' => $course->id] + $options);
        return $DB->get_record('sideline', ['id' => $instance->id]);
    }

    /**
     * Export, encode, decode and migrate, as transfer.php does it.
     *
     * @param \stdClass $sideline Instance.
     * @return array
     */
    private function roundtrip(\stdClass $sideline): array {
        return config_transfer::migrate(json_decode(json_encode(config_transfer::export($sideline)), true));
    }

    public function test_sources_groups_and_personas_travel_and_are_resolved_by_name(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $further = $gen->create_course(['shortname' => 'FURTHER']);
        $gen->enrol_user($this->teacher->id, $further->id, 'editingteacher');
        $category = $gen->create_category(['name' => 'Engineering', 'idnumber' => 'ENG']);
        $manager = $DB->get_field('role', 'id', ['shortname' => 'manager']);
        role_assign($manager, $this->teacher->id, \context_coursecat::instance($category->id)->id);
        $red = $gen->create_group(['courseid' => $this->source->id, 'name' => 'Red team']);
        $sideline = $this->coach($this->source, ['sharingmode' => 'closed', 'grouppublish' => 'general']);
        instance_config::save_sources($sideline, [$further->id], (int) $this->teacher->id);
        instance_config::save_source_categories($sideline, [$category->id], (int) $this->teacher->id);
        instance_config::save_groups((int) $sideline->id, (int) $this->source->id, [(int) $red->id], []);

        $data = $this->roundtrip($DB->get_record('sideline', ['id' => $sideline->id]));
        $this->assertSame([['shortname' => 'FURTHER', 'idnumber' => '']], $data['sources']['courses']);
        $this->assertSame('Red team', $data['groups']['items'][0]['name']);
        $this->assertStringNotContainsString('"id"', json_encode($data['groups']), 'No database ids of groups');

        $tred = $gen->create_group(['courseid' => $this->target->id, 'name' => 'Red team']);
        $target = $this->coach($this->target);
        $preview = config_transfer::preview($target, $data, config_transfer::PARTS, 'replace');
        $this->assertSame([], $preview['warnings']);
        config_transfer::apply($target, $preview['plan']);
        $this->assertSame([(int) $further->id], instance_config::get_sources((int) $target->id));
        $this->assertSame([(int) $category->id], instance_config::get_source_categories((int) $target->id));
        $this->assertSame([[(int) $tred->id], []], instance_config::get_groups((int) $target->id));
        $this->assertSame('closed', $DB->get_field('sideline', 'sharingmode', ['id' => $target->id]));
    }

    public function test_sources_the_importer_may_not_manage_are_skipped_with_a_warning(): void {
        $foreign = $this->getDataGenerator()->create_course(['shortname' => 'FOREIGN']);
        $sideline = $this->coach($this->target);
        $data = config_transfer::migrate(['format' => config_transfer::FORMAT, 'version' => 3,
            'sources' => ['courses' => [['shortname' => 'FOREIGN'], ['shortname' => 'NOWHERE']], 'categories' => []]]);
        $preview = config_transfer::preview($sideline, $data, ['sources'], 'replace');
        $this->assertCount(2, $preview['warnings']);
        config_transfer::apply($sideline, $preview['plan']);
        $this->assertSame([], instance_config::get_sources((int) $sideline->id));
        $this->assertNotEmpty($foreign);
    }

    public function test_sharing_is_never_switched_on_for_all_groups_implicitly(): void {
        global $DB;
        $sideline = $this->coach($this->target);
        // Settings only, without groups: the target admits no groups explicitly.
        $data = config_transfer::migrate(['format' => config_transfer::FORMAT, 'version' => 3,
            'settings' => ['sharingmode' => 'open', 'grouppublish' => 'general']]);
        $preview = config_transfer::preview($sideline, $data, ['settings'], 'replace');
        $this->assertContains(get_string('transfer_sharing_off', 'mod_sideline'), $preview['warnings']);
        config_transfer::apply($sideline, $preview['plan']);
        $this->assertSame('individual', $DB->get_field('sideline', 'sharingmode', ['id' => $sideline->id]));

        // Groups that cannot be resolved in the target: sharing stays off as well.
        $data = config_transfer::migrate(['format' => config_transfer::FORMAT, 'version' => 3,
            'settings' => ['sharingmode' => 'closed'], 'groups' => ['all' => false, 'items' => [['type' => 'group',
            'name' => 'Missing team']]]]);
        $preview = config_transfer::preview(
            $DB->get_record('sideline', ['id' => $sideline->id]),
            $data,
            ['settings', 'groups'],
            'replace'
        );
        config_transfer::apply($DB->get_record('sideline', ['id' => $sideline->id]), $preview['plan']);
        $this->assertSame('individual', $DB->get_field('sideline', 'sharingmode', ['id' => $sideline->id]));
    }

    public function test_older_files_never_admit_all_groups(): void {
        global $DB;
        $sideline = $this->coach($this->target);
        $data = config_transfer::migrate(['format' => config_transfer::FORMAT, 'version' => 2,
            'settings' => ['sharingmode' => 'closed']]);
        $this->assertTrue($data['groups']['unknown']);
        $preview = config_transfer::preview($sideline, $data, ['settings', 'groups'], 'replace');
        config_transfer::apply($sideline, $preview['plan']);
        $this->assertSame('individual', $DB->get_field('sideline', 'sharingmode', ['id' => $sideline->id]));
    }

    public function test_activities_with_the_same_name_are_never_guessed(): void {
        $gen = $this->getDataGenerator();
        $gen->create_module('page', ['course' => $this->target->id, 'name' => 'Week 1', 'section' => 1]);
        $gen->create_module('page', ['course' => $this->target->id, 'name' => 'Week 1', 'section' => 1]);
        $other = $gen->create_module('page', ['course' => $this->target->id, 'name' => 'Week 1', 'section' => 2]);
        $sideline = $this->coach($this->target);
        $data = config_transfer::migrate(['format' => config_transfer::FORMAT, 'version' => 3, 'scope' => ['section' => 0,
            'activities' => [
                ['modname' => 'page', 'name' => 'Week 1', 'section' => 1],
                ['modname' => 'page', 'name' => 'Week 1', 'section' => 2],
                ['modname' => 'page', 'name' => 'Week 1'],
            ]]]);
        $preview = config_transfer::preview($sideline, $data, ['scope'], 'replace');
        $this->assertSame([(int) $other->cmid], $preview['plan']['scope']['cmids'], 'Only the unique match by section');
        $this->assertCount(2, $preview['warnings'], 'Two explicit conflicts, no silent first match');
    }

    public function test_persona_definitions_only_for_administrators_and_only_as_drafts(): void {
        global $DB;
        $sideline = $this->coach($this->target);
        $data = config_transfer::migrate(['format' => config_transfer::FORMAT, 'version' => 3, 'personas' => [
            ['key' => 'site_tutor', 'definition' => ['shortname' => 'tutor', 'name' => 'Tutor',
                'style' => 'You are a calm tutor.', 'opener' => '']],
            ['key' => 'mentor'],
            ['key' => 'site_unknown'],
        ]]);
        $preview = config_transfer::preview($sideline, $data, ['personas'], 'replace');
        $this->assertSame([], $preview['plan']['personas'], 'Teachers cannot import persona definitions');

        $this->setAdminUser();
        $preview = config_transfer::preview($sideline, $data, ['personas'], 'replace');
        config_transfer::apply($sideline, $preview['plan']);
        $this->assertSame('draft', $DB->get_field('sideline_persona', 'status', ['shortname' => 'tutor']));
        $this->assertContains(get_string('transfer_persona_unknown', 'mod_sideline', 'site_unknown'), $preview['warnings']);
    }

    /**
     * Malformed files.
     *
     * @return array
     */
    public static function malformed_provider(): array {
        $base = ['format' => 'mod_sideline_config', 'version' => 3];
        return [
            'settings as list' => [$base + ['settings' => ['open']]],
            'unknown setting' => [$base + ['settings' => ['adminpassword' => 'x']]],
            'nested setting' => [$base + ['settings' => ['persona' => ['a' => 1]]]],
            'rules as object' => [$base + ['rules' => ['a' => 1]]],
            'activity not an object' => [$base + ['scope' => ['activities' => ['page']]]],
            'version as string' => [['format' => 'mod_sideline_config', 'version' => '3']],
            'groups as list' => [$base + ['groups' => [1, 2]]],
        ];
    }

    /**
     * Test with data provider.
     *
     * @dataProvider malformed_provider
     * @param array $data Data.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformed_provider')]
    public function test_malformed_files_are_rejected_as_a_whole(array $data): void {
        $this->expectException(\moodle_exception::class);
        config_transfer::migrate($data);
    }

    public function test_duplicate_rules_and_foreign_ids_are_not_taken_over(): void {
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->target->id, 'name' => 'Lab']);
        $sideline = $this->coach($this->target);
        $rule = static fn(string $key, array $condition): array => ['key' => $key, 'category' => 'next', 'priority' => 10,
            'match' => 'all', 'conditions' => [$condition + ['type' => 'activity', 'field' => 'completion', 'is' => 'no']],
            'action' => 'open', 'templates' => ['en' => 'Do not forget {label}.']];
        $byname = ['cmid' => 999, 'activity' => ['modname' => 'page', 'name' => 'Lab']];
        $data = config_transfer::migrate(['format' => config_transfer::FORMAT, 'version' => 3, 'rules' => [
            ['enabled' => 1, 'definition' => $rule('custom_a', $byname)],
            ['enabled' => 1, 'definition' => $rule('custom_a', $byname)],
            // A bare id of another course is never trusted, even if it happens to exist here.
            ['enabled' => 1, 'definition' => $rule('custom_b', ['cmid' => (int) $page->cmid])],
        ]]);
        $preview = config_transfer::preview($sideline, $data, ['rules'], 'replace');
        $this->assertCount(1, $preview['plan']['rules']);
        $this->assertSame((int) $page->cmid, $preview['plan']['rules'][0]['definition']['conditions'][0]['cmid']);
        $this->assertCount(2, $preview['warnings'], 'The duplicate and the bare id');
    }
}
