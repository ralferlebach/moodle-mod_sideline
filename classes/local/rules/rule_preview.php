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

namespace mod_sideline\local\rules;

use mod_sideline\local\model\activity_state;
use mod_sideline\local\service\evaluation_context;
use mod_sideline\local\service\message_composer;
use mod_sideline\local\service\rule_evaluator;

/**
 * Preview of rules on synthetic sample states. Learner data is never used (L-16).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rule_preview {
    /**
     * Render the commentary a sample learner would get.
     *
     * @param \stdClass $sideline Instance.
     * @param array $rule Validated rule definition.
     * @param string $scenario none_done, half_done, deadline_tomorrow or all_done.
     * @return array Commentary blocks (key, text).
     */
    public static function preview(\stdClass $sideline, array $rule, string $scenario): array {
        $now = time();
        $cms = [];
        foreach (get_fast_modinfo((int) $sideline->course)->get_cms() as $cm) {
            if ($cm->modname !== 'sideline' && !$cm->get_delegated_section_info()) {
                $cms[$cm->id] = $cm;
            }
            if (count($cms) >= 6) {
                break;
            }
        }
        $states = [];
        $i = 0;
        $n = count($cms);
        foreach ($cms as $cm) {
            $done = match ($scenario) {
                'half_done' => $i < intdiv($n, 2),
                'all_done' => true,
                default => false,
            };
            $states[$cm->id] = new activity_state(
                cmid: (int) $cm->id,
                label: clean_param(format_string($cm->name), PARAM_NOTAGS),
                visible: true,
                available: activity_state::YES,
                completion: $done ? activity_state::YES : activity_state::NO,
                deadline: ($scenario === 'deadline_tomorrow' && $i === 0) ? $now + DAYSECS : null,
                lastactivity: $done ? $now - HOURSECS : null,
                courseid: (int) $cm->course,
                modname: (string) $cm->modname,
            );
            $i++;
        }
        $ctx = new evaluation_context(customrules: [$rule], currentkeys: [$rule['key']]);
        $recommendations = (new rule_evaluator())->evaluate($states, $now, $ctx);
        // No action links in the preview: they would point at real activities of the teacher's view.
        $commentary = (new message_composer())->compose($recommendations, [], (string) $sideline->persona, current_language());
        return array_map(static fn($b) => ['key' => $b['key'], 'text' => $b['text']], $commentary['blocks']);
    }
}
