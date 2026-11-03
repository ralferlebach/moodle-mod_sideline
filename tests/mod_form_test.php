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

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_sideline_mod_form::class)]
/**
 * The settings form opens for every stored value.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_sideline_mod_form
 */
final class mod_form_test extends \advanced_testcase {
    /**
     * Stored pauses between two questions, in seconds, and how the form shows them.
     *
     * @return array
     */
    public static function cooldown_provider(): array {
        return [
            'none (import, generator)' => [0, '0'],
            'not a whole minute (import)' => [90, '2'],
            'a day' => [DAYSECS, '1'],
        ];
    }

    /**
     * Test with data provider.
     *
     * @dataProvider cooldown_provider
     * @param int $seconds Stored value.
     * @param string $number Number shown in the field.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('cooldown_provider')]
    public function test_the_settings_form_opens_for_every_stored_pause(int $seconds, string $number): void {
        global $CFG, $DB, $PAGE;
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/sideline/mod_form.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module('sideline', ['course' => $course->id]);
        $DB->set_field('sideline', 'dialogcooldown', $seconds, ['id' => $instance->id]);

        $cm = get_coursemodule_from_id('sideline', $instance->cmid, 0, false, MUST_EXIST);
        [$cm, , , $data, $cw] = get_moduleinfo_data($cm, $course);
        $PAGE->set_course($course);
        $PAGE->set_url('/course/modedit.php', ['update' => $cm->id]);
        $PAGE->set_context(\context_module::instance($cm->id));
        $form = new \mod_sideline_mod_form($data, $cw->section, $cm, $course);
        $form->set_data($data);
        $html = $form->render();
        $this->assertMatchesRegularExpression('/name="dialogcooldown\[number\]"[^>]*value="' . $number . '"/', $html);
    }
}
