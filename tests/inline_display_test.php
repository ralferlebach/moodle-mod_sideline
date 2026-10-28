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

/**
 * Inline display on the course page (P1-11).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_sideline\output\panel
 * @covers ::sideline_get_coursemodule_info
 * @covers ::sideline_cm_info_dynamic
 * @covers ::sideline_cm_info_view
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_sideline\output\panel::class)]
#[\PHPUnit\Framework\Attributes\CoversFunction('sideline_get_coursemodule_info')]
#[\PHPUnit\Framework\Attributes\CoversFunction('sideline_cm_info_dynamic')]
#[\PHPUnit\Framework\Attributes\CoversFunction('sideline_cm_info_view')]
final class inline_display_test extends \advanced_testcase {
    /**
     * The course module as the course page sees it.
     *
     * @param \stdClass $course Course.
     * @param int $cmid Course module id.
     * @return \cm_info
     */
    private function cm(\stdClass $course, int $cmid): \cm_info {
        return get_fast_modinfo($course)->get_cm($cmid);
    }

    public function test_inline_instances_render_their_own_panel_without_any_learner_data(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $student = $gen->create_and_enrol($course, 'student');
        $a = $gen->create_module('sideline', ['course' => $course->id, 'display' => 'inline']);
        $b = $gen->create_module('sideline', ['course' => $course->id, 'display' => 'inline']);
        $this->setUser($student);
        $cma = $this->cm($course, (int) $a->cmid);
        $cmb = $this->cm($course, (int) $b->cmid);
        $this->assertNull($cma->url, 'Inline instances have no link to a separate page');
        $html = $cma->get_formatted_content();
        if (\mod_sideline\output\panel::uses_react()) {
            $this->assertStringContainsString('data-react-component="@moodle/lms/mod_sideline/CommentaryPanel"', $html);
        } else {
            // Moodle 4.5 to 5.2: the AMD panel with the same props.
            $this->assertStringContainsString('mod_sideline-amdpanel', $html);
        }
        $this->assertStringContainsString('&quot;cmid&quot;:' . $a->cmid . ',', $html);
        $this->assertStringContainsString('&quot;cmid&quot;:' . $b->cmid . ',', $cmb->get_formatted_content());
        // Nothing personal is in the page HTML: the panel loads it itself after the access checks.
        $this->assertStringNotContainsString('blocks', $html);
    }

    /**
     * The mount marker of the panel in this Moodle version.
     *
     * @return string
     */
    private static function marker(): string {
        return \mod_sideline\output\panel::uses_react() ? 'data-react-component' : 'mod_sideline-amdpanel';
    }

    public function test_page_mode_guests_and_hidden_instances_get_no_inline_panel(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $student = $gen->create_and_enrol($course, 'student');
        $page = $gen->create_module('sideline', ['course' => $course->id, 'display' => 'page']);
        $hidden = $gen->create_module('sideline', ['course' => $course->id, 'display' => 'inline', 'visible' => 0]);
        $inline = $gen->create_module('sideline', ['course' => $course->id, 'display' => 'inline']);

        $this->setUser($student);
        $this->assertNotNull($this->cm($course, (int) $page->cmid)->url);
        $this->assertStringNotContainsString(
            self::marker(),
            $this->cm($course, (int) $page->cmid)->get_formatted_content(),
        );
        $this->assertStringNotContainsString(
            self::marker(),
            $this->cm($course, (int) $hidden->cmid)->get_formatted_content(),
        );

        $this->setGuestUser();
        $this->assertStringNotContainsString(
            self::marker(),
            $this->cm($course, (int) $inline->cmid)->get_formatted_content(),
        );
    }
}
