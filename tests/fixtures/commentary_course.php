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

/**
 * Shared course setup of the commentary tests: a course with completion, one learner, two pages.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_sideline\tests;

use mod_sideline\local\ai\ai_gateway;
use mod_sideline\local\service\commentary_service;

/**
 * Course, learner and two manually completed pages, plus helpers to create a coach and to ask it.
 *
 * Used by test classes extending \advanced_testcase.
 */
trait commentary_course {
    /** @var \stdClass Course. */
    private \stdClass $course;
    /** @var \stdClass Learner. */
    private \stdClass $student;
    /** @var \stdClass First observed activity. */
    private \stdClass $page1;
    /** @var \stdClass Second observed activity. */
    private \stdClass $page2;

    /**
     * Create the course, the learner and the two pages "Alpha unit" and "Beta unit".
     *
     * @param array $course Extra course fields.
     * @param array $student Extra user fields of the learner.
     */
    private function create_commentary_course(array $course = [], array $student = []): void {
        $this->resetAfterTest();
        set_config('enablecompletion', 1);
        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course(['enablecompletion' => 1] + $course);
        $this->student = $gen->create_and_enrol($this->course, 'student', $student);
        $manual = ['completion' => COMPLETION_TRACKING_MANUAL];
        $this->page1 = $gen->create_module('page', ['course' => $this->course->id, 'name' => 'Alpha unit'] + $manual);
        $this->page2 = $gen->create_module('page', ['course' => $this->course->id, 'name' => 'Beta unit'] + $manual);
    }

    /**
     * Create a Sideline instance and return it with its context.
     *
     * @param array $options Instance options.
     * @return array The instance record, the cm and the context.
     */
    private function sideline(array $options = []): array {
        $instance = $this->getDataGenerator()->create_module('sideline', ['course' => $this->course->id] + $options);
        $cm = get_coursemodule_from_instance('sideline', $instance->id);
        return [$instance, $cm, \context_module::instance($cm->id)];
    }

    /**
     * Get the commentary for the learner through the service.
     *
     * @param \stdClass $instance Instance.
     * @param \context_module $context Context.
     * @param fake_text_generator|null $fake Fake generator, when AI should be possible.
     * @param int|null $now Time.
     * @return array
     */
    private function get(
        \stdClass $instance,
        \context_module $context,
        ?fake_text_generator $fake = null,
        ?int $now = null,
    ): array {
        global $DB;
        $this->setUser($this->student);
        $record = $DB->get_record('sideline', ['id' => $instance->id], '*', MUST_EXIST);
        $service = new commentary_service(new ai_gateway($fake ?? new fake_text_generator()));
        return $service->get($record, $this->course, $this->student, $context, $now ?? time());
    }
}
