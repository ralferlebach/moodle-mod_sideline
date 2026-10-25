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

namespace mod_sideline\local\consent;

use mod_sideline\local\cache\commentary_cache;

/**
 * Optional per-activity consent gate and its state machine (P-45 to P-47, ADR-04).
 *
 * Before the status is accepted no personal state is computed and no text is generated. The only
 * data processed beforehand is the consent status itself. The legal basis and the wording of the
 * information text remain to be reviewed by data protection.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class consent_manager {
    /** @var string No decision asked for yet. */
    public const NOT_REQUESTED = 'not_requested';
    /** @var string The learner agreed. */
    public const ACCEPTED = 'accepted';
    /** @var string The learner declined. */
    public const DECLINED = 'declined';
    /** @var string The learner agreed once and withdrew. */
    public const WITHDRAWN = 'withdrawn';
    /** @var string The information changed after the learner agreed. */
    public const RECONSENT_REQUIRED = 'reconsent_required';

    /**
     * Whether personal analysis and output are allowed for this user now.
     *
     * @param \stdClass $sideline Instance record.
     * @param int $userid User id.
     * @return bool
     */
    public static function allows_processing(\stdClass $sideline, int $userid): bool {
        $status = self::status($sideline, $userid);
        if (empty($sideline->consentrequired)) {
            // Switching the gate off must not override a learner's own "no" (L-20).
            return !in_array($status, [self::DECLINED, self::WITHDRAWN], true);
        }
        return $status === self::ACCEPTED;
    }

    /**
     * The current consent status of a user, taking a changed information version into account.
     *
     * @param \stdClass $sideline Instance record.
     * @param int $userid User id.
     * @return string One of the status constants.
     */
    public static function status(\stdClass $sideline, int $userid): string {
        global $DB;

        $row = $DB->get_record('sideline_consent', ['sidelineid' => $sideline->id, 'userid' => $userid]);
        if (!$row) {
            return self::NOT_REQUESTED;
        }
        if ($row->status === self::ACCEPTED && (int) $row->infoversion < (int) $sideline->consentversion) {
            return self::RECONSENT_REQUIRED;
        }
        return $row->status;
    }

    /**
     * Record a decision. Any user may change their own decision at any time.
     *
     * @param \stdClass $sideline Instance record.
     * @param int $userid User id.
     * @param bool $accept True to accept, false to decline or withdraw.
     * @param int $contextid Module context id, stored as evidence of where the decision was made.
     * @param int $now Current time.
     * @return string The new status.
     */
    public static function record_decision(\stdClass $sideline, int $userid, bool $accept, int $contextid, int $now): string {
        global $DB;

        $current = self::status($sideline, $userid);
        if ($accept) {
            $new = self::ACCEPTED;
        } else {
            // Declining before ever accepting is "declined"; leaving after accepting is a withdrawal.
            $new = in_array($current, [self::ACCEPTED, self::RECONSENT_REQUIRED], true) ? self::WITHDRAWN : self::DECLINED;
        }

        $row = $DB->get_record('sideline_consent', ['sidelineid' => $sideline->id, 'userid' => $userid]);
        $record = (object) [
            'sidelineid' => $sideline->id,
            'userid' => $userid,
            'status' => $new,
            'infoversion' => (int) $sideline->consentversion,
            'timemodified' => $now,
        ];
        if ($row) {
            $record->id = $row->id;
            $DB->update_record('sideline_consent', $record);
        } else {
            $record->timecreated = $now;
            $DB->insert_record('sideline_consent', $record);
        }

        // Append-only evidence: status, information version, context and time.
        $DB->insert_record('sideline_consent_log', (object) [
            'sidelineid' => $sideline->id,
            'userid' => $userid,
            'status' => $new,
            'infoversion' => (int) $sideline->consentversion,
            'contextid' => $contextid,
            'timecreated' => $now,
        ]);

        // Any decision other than accept removes what was generated about the person (P-47). The
        // generation lock is taken first, so a generation that is in flight has either stored its text
        // already (and it is deleted here) or will see the new status before it stores anything.
        if ($new !== self::ACCEPTED) {
            // No processing means no publication either: group sharing ends with the consent (L-G06).
            if ($DB->record_exists('sideline_publish', ['sidelineid' => $sideline->id, 'userid' => $userid, 'publish' => 1])) {
                \mod_sideline\local\group\sharing_manager::record($sideline, $userid, false, false, $contextid, $now);
            }
            $lock = \core\lock\lock_config::get_lock_factory('mod_sideline')
                ->get_lock('gen_' . $sideline->id . '_' . $userid, 10);
            try {
                commentary_cache::invalidate_user((int) $sideline->id, $userid);
            } finally {
                if ($lock) {
                    $lock->release();
                }
            }
        }
        return $new;
    }
}
