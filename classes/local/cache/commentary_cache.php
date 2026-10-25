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

namespace mod_sideline\local\cache;

use mod_sideline\local\ai\persona_registry;
use mod_sideline\local\model\activity_state;
use mod_sideline\local\service\rule_evaluator;

/**
 * Per-user commentary cache with fingerprint and time-based expiry (P-27 to P-30).
 *
 * A cached text is valid while the fingerprint matches, the row is not marked dirty and
 * nexttransition lies in the future. Texts are never shared between users: every row belongs to
 * exactly one user and one instance.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class commentary_cache {
    /**
     * Fingerprint of everything that may change the text (P-27).
     *
     * @param \stdClass $sideline Instance record.
     * @param int $userid User id.
     * @param activity_state[] $states Observed activity states.
     * @param string $persona Persona key.
     * @param string $lang Language code.
     * @param bool $aiallowed Whether AI generation is allowed for this user right now.
     * @return string sha256 hex digest.
     */
    public static function fingerprint(
        \stdClass $sideline,
        int $userid,
        array $states,
        string $persona,
        string $lang,
        bool $aiallowed,
    ): string {
        $parts = [
            'u' => $userid,
            'i' => (int) $sideline->id,
            'cfg' => (int) $sideline->configversion,
            'rules' => rule_evaluator::RULES_VERSION,
            'persona' => $persona . ':' . persona_registry::VERSION,
            'lang' => $lang,
            'ai' => $aiallowed ? 1 : 0,
            'states' => array_map(static fn(activity_state $s): array => $s->to_fingerprint_array(), array_values($states)),
        ];
        return hash('sha256', json_encode($parts));
    }

    /**
     * Look up a still valid cache entry.
     *
     * @param int $sidelineid Instance id.
     * @param int $userid User id.
     * @param string $fingerprint Current fingerprint.
     * @param int $now Current time.
     * @return array|null The decoded commentary, or null when none is valid.
     */
    public static function get(int $sidelineid, int $userid, string $fingerprint, int $now): ?array {
        return self::lookup($sidelineid, $userid, $fingerprint, $now)[0];
    }

    /**
     * The raw cache row of a user and instance, valid or not.
     *
     * @param int $sidelineid Instance id.
     * @param int $userid User id.
     * @return \stdClass|null
     */
    public static function row(int $sidelineid, int $userid): ?\stdClass {
        global $DB;

        return $DB->get_record('sideline_cache', ['sidelineid' => $sidelineid, 'userid' => $userid]) ?: null;
    }

    /**
     * Rule keys of the commentary in a row, whether or not it is still valid.
     *
     * @param \stdClass|null $row Cache row.
     * @return string[]
     */
    public static function keys_of(?\stdClass $row): array {
        if (!$row) {
            return [];
        }
        $decoded = json_decode((string) $row->commentary, true);
        return array_values(array_map(static fn($b) => (string) ($b['key'] ?? ''), $decoded['blocks'] ?? []));
    }

    /**
     * Evaluate a row against the current fingerprint.
     *
     * @param \stdClass|null $row Cache row.
     * @param string $fingerprint Current fingerprint.
     * @param int $now Current time.
     * @return array [commentary or null, stamp or null]
     */
    public static function check(?\stdClass $row, string $fingerprint, int $now): array {
        if (!$row) {
            return [null, null];
        }
        $stamp = (int) $row->stamp;
        if ($row->dirty || $row->fingerprint !== $fingerprint) {
            return [null, $stamp];
        }
        if ((int) $row->nexttransition > 0 && (int) $row->nexttransition <= $now) {
            return [null, $stamp];
        }
        $decoded = json_decode($row->commentary, true);
        return [is_array($decoded) ? $decoded : null, $stamp];
    }

    /**
     * Look up an entry and report the stamp a later write must match (compare and swap, P1-13).
     *
     * @param int $sidelineid Instance id.
     * @param int $userid User id.
     * @param string $fingerprint Current fingerprint.
     * @param int $now Current time.
     * @return array [commentary or null, stamp of the row or null when there is no row]
     */
    public static function lookup(int $sidelineid, int $userid, string $fingerprint, int $now): array {
        global $DB;

        $row = $DB->get_record('sideline_cache', ['sidelineid' => $sidelineid, 'userid' => $userid]);
        if (!$row) {
            return [null, null];
        }
        $stamp = (int) $row->stamp;
        if ($row->dirty || $row->fingerprint !== $fingerprint) {
            return [null, $stamp];
        }
        if ((int) $row->nexttransition > 0 && (int) $row->nexttransition <= $now) {
            return [null, $stamp];
        }
        $decoded = json_decode($row->commentary, true);
        return [is_array($decoded) ? $decoded : null, $stamp];
    }

    /**
     * Store commentary for a user and instance.
     *
     * The write is conditional: it only replaces the row when nobody invalidated it since the
     * generation started (the stamp still matches). A late write after an invalidation is dropped,
     * so an event during a slow AI call can never be overwritten with older text (P1-13).
     *
     * @param int $sidelineid Instance id.
     * @param int $userid User id.
     * @param string $fingerprint Fingerprint the text was generated for.
     * @param array $commentary Commentary structure.
     * @param int $nexttransition Unix time of the next possible change.
     * @param int $now Current time.
     * @param int|null|false $expectedstamp Stamp seen at lookup, null when there was no row, false to write unconditionally.
     * @return bool Whether the text was stored.
     */
    public static function put(
        int $sidelineid,
        int $userid,
        string $fingerprint,
        array $commentary,
        int $nexttransition,
        int $now,
        int|null|false $expectedstamp = false,
    ): bool {
        global $DB;

        $values = [
            'fingerprint' => $fingerprint,
            'source' => substr((string) ($commentary['source'] ?? 'template'), 0, 12),
            'commentary' => json_encode($commentary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'dirty' => 0,
            'nexttransition' => $nexttransition,
            'timemodified' => $now,
        ];
        $existing = $DB->get_record('sideline_cache', ['sidelineid' => $sidelineid, 'userid' => $userid], 'id, stamp');

        if (!$existing) {
            if ($expectedstamp !== false && $expectedstamp !== null) {
                // The row existed at lookup and was deleted since (withdrawal, deletion): do not resurrect it.
                return false;
            }
            try {
                $DB->insert_record('sideline_cache', (object) ($values + [
                    'sidelineid' => $sidelineid, 'userid' => $userid, 'stamp' => 0, 'timecreated' => $now,
                ]));
                return true;
            } catch (\dml_write_exception $e) {
                // A parallel request inserted first. Its text is as fresh as ours.
                return false;
            }
        }

        if ($expectedstamp === false) {
            $DB->update_record('sideline_cache', (object) ($values + ['id' => $existing->id]));
            return true;
        }
        if ($expectedstamp === null || (int) $existing->stamp !== (int) $expectedstamp) {
            return false;
        }
        // Atomic compare and swap in one statement: no other writer can slip in between.
        $sets = [];
        $params = [];
        foreach ($values as $name => $value) {
            $sets[] = "$name = :$name";
            $params[$name] = $value;
        }
        $params['id'] = $existing->id;
        $params['stamp'] = (int) $expectedstamp;
        $DB->execute('UPDATE {sideline_cache} SET ' . implode(', ', $sets) . ' WHERE id = :id AND stamp = :stamp', $params);
        return (int) $DB->get_field('sideline_cache', 'stamp', ['id' => $existing->id]) === (int) $expectedstamp;
    }

    /**
     * Mark all cached commentary of a user in a course dirty. Observers call this and nothing else (P-29).
     *
     * @param int $courseid Course id.
     * @param int $userid User id.
     */
    public static function mark_user_dirty(int $courseid, int $userid): void {
        global $DB;

        $DB->execute(
            "UPDATE {sideline_cache} SET dirty = 1, stamp = stamp + 1
              WHERE userid = :userid
                AND (sidelineid IN (SELECT id FROM {sideline} WHERE course = :courseid)
                     OR sidelineid IN (SELECT sidelineid FROM {sideline_source} WHERE courseid = :sourceid))",
            ['userid' => $userid, 'courseid' => $courseid, 'sourceid' => $courseid]
        );
    }

    /**
     * Mark all cached commentary of a whole course dirty, for structural changes.
     *
     * @param int $courseid Course id.
     */
    public static function mark_course_dirty(int $courseid): void {
        global $DB;

        $DB->execute(
            "UPDATE {sideline_cache} SET dirty = 1, stamp = stamp + 1
              WHERE sidelineid IN (SELECT id FROM {sideline} WHERE course = :courseid)
                 OR sidelineid IN (SELECT sidelineid FROM {sideline_source} WHERE courseid = :sourceid)",
            ['courseid' => $courseid, 'sourceid' => $courseid]
        );
    }

    /**
     * Mark every cached commentary of the site dirty, after a site-wide change such as a persona.
     */
    public static function mark_all_dirty(): void {
        global $DB;

        $DB->execute('UPDATE {sideline_cache} SET dirty = 1, stamp = stamp + 1');
    }

    /**
     * Drop all cached commentary of one instance.
     *
     * @param int $sidelineid Instance id.
     */
    public static function invalidate_instance(int $sidelineid): void {
        global $DB;

        $DB->delete_records('sideline_cache', ['sidelineid' => $sidelineid]);
    }

    /**
     * Drop cached commentary of one user in one instance, for consent withdrawal and similar (P-47).
     *
     * @param int $sidelineid Instance id.
     * @param int $userid User id.
     */
    public static function invalidate_user(int $sidelineid, int $userid): void {
        global $DB;

        $DB->delete_records('sideline_cache', ['sidelineid' => $sidelineid, 'userid' => $userid]);
    }

    /**
     * Delete entries nobody has touched for a long time (retention).
     *
     * @param int $olderthan Unix time: rows last modified before this are deleted.
     * @return int Number of deleted rows.
     */
    public static function purge_older_than(int $olderthan): int {
        global $DB;

        $count = $DB->count_records_select('sideline_cache', 'timemodified < ?', [$olderthan]);
        $DB->delete_records_select('sideline_cache', 'timemodified < ?', [$olderthan]);
        return $count;
    }
}
