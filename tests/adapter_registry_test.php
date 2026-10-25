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

namespace mod_sideline\local\adapter;

/**
 * Adapter contract: discovery, type checks and safe fallback (P1-06).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(registry::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(facts::class)]
final class adapter_registry_test extends \advanced_testcase {
    #[\Override]
    protected function tearDown(): void {
        registry::set_override_for_testing(null);
        parent::tearDown();
    }

    public function test_installed_adapters_are_discovered(): void {
        $this->assertArrayHasKey('assign', registry::adapters());
        $this->assertArrayHasKey('quiz', registry::adapters());
    }

    public function test_a_failing_adapter_falls_back_and_marks_partial_quality(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $broken = new class extends base {
            /**
             * Module name.
             *
             * @return string
             */
            public function modname(): string {
                return 'page';
            }
            /**
             * Collect.
             *
             * @param array $cms Course modules.
             * @param int $userid User.
             * @param int $now Time.
             * @return array
             */
            public function collect(array $cms, int $userid, int $now): array {
                throw new \RuntimeException('boom');
            }
        };
        registry::set_override_for_testing(['page' => $broken]);
        $cm = get_fast_modinfo($course)->get_cm($page->cmid);
        $collector = new \mod_sideline\local\service\state_collector();
        $states = $collector->collect($course, 2, [$cm->id => $cm]);
        $this->assertDebuggingCalled();
        $this->assertSame(['page'], $collector->failedadapters);
        $this->assertSame('partial', $states[$cm->id]->dataquality);
    }

    public function test_foreign_ids_and_absurd_values_are_dropped(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $sloppy = new class extends base {
            /**
             * Module name.
             *
             * @return string
             */
            public function modname(): string {
                return 'page';
            }
            /**
             * Collect.
             *
             * @param array $cms Course modules.
             * @param int $userid User.
             * @param int $now Time.
             * @return array
             */
            public function collect(array $cms, int $userid, int $now): array {
                $id = array_key_first($cms);
                return [$id => new facts(deadline: -5, attemptsused: 2, attemptsallowed: 999999), 424242 => new facts(deadline: 1)];
            }
        };
        registry::set_override_for_testing(['page' => $sloppy]);
        $cm = get_fast_modinfo($course)->get_cm($page->cmid);
        [$facts] = registry::collect([$cm->id => $cm], 2, time());
        $this->assertSame([(int) $cm->id], array_keys($facts));
        $this->assertNull($facts[$cm->id]->deadline);
        $this->assertNull($facts[$cm->id]->attemptsallowed);
        $this->assertSame(2, $facts[$cm->id]->attemptsused);
    }
}
