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

namespace mod_sideline\local\persona;

use mod_sideline\local\ai\persona_registry;
use mod_sideline\local\instance_config;

/**
 * Decides which persona speaks to a learner in an instance (P1-07).
 *
 * Order: the learner's own choice (if the instance allows choice and the persona is offered and
 * usable), then the instance default, then the first usable offered persona, then the built-in
 * default. The choice is the learner's own data and is shown to nobody else.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class persona_resolver {
    /**
     * Usable offered personas of an instance.
     *
     * @param \stdClass $sideline Instance record.
     * @return string[]
     */
    public static function offered(\stdClass $sideline): array {
        return array_values(array_filter(
            instance_config::offered_personas($sideline),
            static fn(string $key): bool => persona_registry::is_valid($key)
        ));
    }

    /**
     * The persona key for a learner.
     *
     * @param \stdClass $sideline Instance record.
     * @param int $userid User id.
     * @return string
     */
    public static function for_user(\stdClass $sideline, int $userid): string {
        global $DB;

        $offered = self::offered($sideline);
        if (!empty($sideline->personachoice)) {
            $choice = $DB->get_field('sideline_pref', 'persona', ['sidelineid' => $sideline->id, 'userid' => $userid]);
            if ($choice && in_array($choice, $offered, true)) {
                return $choice;
            }
        }
        if (in_array($sideline->persona, $offered, true)) {
            return $sideline->persona;
        }
        return $offered[0] ?? persona_registry::DEFAULT_KEY;
    }

    /**
     * Store the learner's choice. Only offered, usable personas may be chosen.
     *
     * @param \stdClass $sideline Instance record.
     * @param int $userid User id.
     * @param string $persona Persona key, empty to go back to the default.
     * @return string The persona now in effect.
     */
    public static function choose(\stdClass $sideline, int $userid, string $persona): string {
        global $DB;

        if (empty($sideline->personachoice)) {
            throw new \moodle_exception('err_personachoice', 'mod_sideline');
        }
        if ($persona !== '' && !in_array($persona, self::offered($sideline), true)) {
            throw new \invalid_parameter_exception('Persona not offered');
        }
        $row = $DB->get_record('sideline_pref', ['sidelineid' => $sideline->id, 'userid' => $userid]);
        if ($persona === '') {
            if ($row) {
                $DB->delete_records('sideline_pref', ['id' => $row->id]);
            }
        } else if ($row) {
            $DB->update_record('sideline_pref', (object) ['id' => $row->id, 'persona' => $persona, 'timemodified' => time()]);
        } else {
            $DB->insert_record('sideline_pref', (object) [
                'sidelineid' => $sideline->id, 'userid' => $userid, 'persona' => $persona, 'timemodified' => time(),
            ]);
        }
        return self::for_user($sideline, $userid);
    }
}
