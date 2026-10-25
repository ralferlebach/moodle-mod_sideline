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

namespace mod_sideline\local\group;

use mod_sideline\local\cache\commentary_cache;

/**
 * The learner's own group sharing decisions (L-G05 to L-G08, P-G03).
 *
 * Two separate, versioned opt-ins, both off by default: "share my completions with my group" and
 * "my name may be shown". Without the first, nothing about the learner reaches anybody else; the
 * second has no effect without the first. Declining has no consequence for the learner's own
 * commentary or for any grade.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sharing_manager {
    /**
     * The learner's current decision, taking a changed information version into account.
     *
     * @param \stdClass $sideline Instance record.
     * @param int $userid User id.
     * @return array publish (bool), named (bool), reconsent (bool: the information changed since).
     */
    public static function status(\stdClass $sideline, int $userid): array {
        global $DB;

        $row = $DB->get_record('sideline_publish', ['sidelineid' => $sideline->id, 'userid' => $userid]);
        if (!$row) {
            return ['publish' => false, 'named' => false, 'reconsent' => false];
        }
        $current = (int) $row->infoversion >= (int) $sideline->groupversion;
        return [
            'publish' => $current && (bool) $row->publish,
            'named' => $current && (bool) $row->publish && (bool) $row->named,
            'reconsent' => !$current && (bool) $row->publish,
        ];
    }

    /**
     * Record a decision of the calling learner.
     *
     * @param \stdClass $sideline Instance record.
     * @param int $userid User id.
     * @param bool $publish Share completions.
     * @param bool $named Show the name.
     * @param int $contextid Context id for the evidence log.
     * @param int $now Current time.
     * @return array The new status.
     */
    public static function record(\stdClass $sideline, int $userid, bool $publish, bool $named, int $contextid, int $now): array {
        $named = $publish && $named;
        // Serialise with every recipient's "recheck contributors and store" step (L-G06 race).
        $lock = self::lock((int) $sideline->id);
        try {
            return self::record_locked($sideline, $userid, $publish, $named, $contextid, $now);
        } finally {
            if ($lock) {
                $lock->release();
            }
        }
    }

    /**
     * The instance-wide sharing lock. Held briefly by a withdrawal and by a recipient's final store.
     *
     * @param int $sidelineid Instance id.
     * @return \core\lock\lock|false
     */
    public static function lock(int $sidelineid) {
        return \core\lock\lock_config::get_lock_factory('mod_sideline')->get_lock('share_' . $sidelineid, 10);
    }

    /**
     * Record a decision while holding the sharing lock.
     *
     * @param \stdClass $sideline Instance record.
     * @param int $userid User id.
     * @param bool $publish Share completions.
     * @param bool $named Show the name.
     * @param int $contextid Context id.
     * @param int $now Current time.
     * @return array The new status.
     */
    private static function record_locked(
        \stdClass $sideline,
        int $userid,
        bool $publish,
        bool $named,
        int $contextid,
        int $now,
    ): array {
        global $DB;

        $old = self::status($sideline, $userid);
        $row = $DB->get_record('sideline_publish', ['sidelineid' => $sideline->id, 'userid' => $userid]);
        $record = (object) [
            'sidelineid' => $sideline->id,
            'userid' => $userid,
            'publish' => $publish ? 1 : 0,
            'named' => $named ? 1 : 0,
            'infoversion' => (int) $sideline->groupversion,
            'timemodified' => $now,
        ];
        if ($row) {
            $record->id = $row->id;
            $DB->update_record('sideline_publish', $record);
        } else {
            $record->timecreated = $now;
            $DB->insert_record('sideline_publish', $record);
        }
        foreach (['publish' => $publish, 'named' => $named] as $kind => $value) {
            if ($old[$kind] !== $value || !$row) {
                $DB->insert_record('sideline_consent_log', (object) [
                    'sidelineid' => $sideline->id,
                    'userid' => $userid,
                    'status' => $value ? 'accepted' : 'withdrawn',
                    'kind' => $kind,
                    'infoversion' => (int) $sideline->groupversion,
                    'contextid' => $contextid,
                    'timecreated' => $now,
                ]);
            }
        }
        // Less sharing than before: other learners' cached texts may mention this person. Drop them at
        // once, under each recipient's generation lock is not needed because the fingerprint of every
        // later request no longer contains this person and generation rechecks contributors (L-G06).
        if (($old['publish'] && !$publish) || ($old['named'] && !$named)) {
            commentary_cache::invalidate_instance((int) $sideline->id);
        }
        return self::status($sideline, $userid);
    }

    /**
     * Which of the given users currently share, and which of them with their name.
     *
     * @param \stdClass $sideline Instance record.
     * @param int[] $userids Candidate users.
     * @return array [user id => named (bool)] for users who share.
     */
    public static function sharing_users(\stdClass $sideline, array $userids): array {
        global $DB;

        if (!$userids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params += ['sidelineid' => $sideline->id, 'version' => (int) $sideline->groupversion];
        $rows = $DB->get_records_select(
            'sideline_publish',
            "sidelineid = :sidelineid AND publish = 1 AND infoversion >= :version AND userid $insql",
            $params,
            '',
            'userid, named'
        );
        $result = [];
        foreach ($rows as $userid => $row) {
            // Sharing without (or after withdrawing) the analysis consent is no sharing (L-G06).
            if (\mod_sideline\local\consent\consent_manager::allows_processing($sideline, (int) $userid)) {
                $result[(int) $userid] = (bool) $row->named;
            }
        }
        return $result;
    }
}
