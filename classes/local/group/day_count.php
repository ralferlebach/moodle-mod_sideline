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

use mod_sideline\local\consent\consent_manager;

/**
 * Who counted in an anonymous group statement today, so that the statement stays the same for the day.
 *
 * Moodle stamps a completion again when it is ticked off and on. Without this record the person would leave
 * the count of the window that ends at the start of the day, and the statement would change during the day.
 * A person counted once today stays counted while they still share and still have the event.
 *
 * The rows hold user ids. They live in the table sideline_daycount, not in a cache: a withdrawal of sharing
 * or of consent, a privacy request, leaving or losing a group, leaving the course or a suspended enrolment, and a
 * change of the sharing terms (forget_if_terms_changed()) delete them at once and completely, on every web
 * server node. Only rows of the current day are read; older rows
 * are deleted with the next statement of the instance and by the scheduled task.
 *
 * Cost per facts() call: one query for the rows of the day, and only when somebody is counted for the first
 * time that day, one insert per new row, one query to delete older days and two queries to recheck sharing.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class day_count {
    /** @var array|null Rows of the day: "groupid_cmid_type" => [user id => true]; null until loaded. */
    private ?array $rows = null;

    /** @var array New rows: list of [user id, group id, cm id, type]. */
    private array $pending = [];

    /** @var \stdClass Instance record. */
    private \stdClass $sideline;

    /** @var int Start of the day. */
    private int $daystart;

    /** @var int[] Groups of the statements. */
    private array $groupids;

    /**
     * Day counts of one instance on one day.
     *
     * @param \stdClass $sideline Instance record.
     * @param int $daystart Start of the day.
     * @param int[] $groupids Groups of the statements.
     */
    public function __construct(\stdClass $sideline, int $daystart, array $groupids) {
        $this->sideline = $sideline;
        $this->daystart = $daystart;
        $this->groupids = array_values(array_map('intval', $groupids));
    }

    /**
     * The people of an anonymous count for the day.
     *
     * @param int $groupid Group.
     * @param int $cmid Activity.
     * @param string $type Event type.
     * @param int[] $before People whose event lies in the window that ends at the start of the day.
     * @param int[] $current Everybody who has the event now, in any window, and still shares.
     * @return int[] Sorted user ids: $before plus those of $current counted earlier today.
     */
    public function counted(int $groupid, int $cmid, string $type, array $before, array $current): array {
        $this->load();
        $key = $groupid . '_' . $cmid . '_' . $type;
        $kept = $this->rows[$key] ?? [];
        $ids = [];
        foreach ($before as $userid) {
            $ids[(int) $userid] = true;
            if (!isset($kept[(int) $userid])) {
                $this->rows[$key][(int) $userid] = true;
                $this->pending[] = [(int) $userid, $groupid, $cmid, $type];
            }
        }
        foreach ($current as $userid) {
            if (isset($kept[(int) $userid])) {
                $ids[(int) $userid] = true;
            }
        }
        $ids = array_keys($ids);
        sort($ids);
        return $ids;
    }

    /**
     * Store the new rows, then check that their people still share and still allow processing.
     *
     * A withdrawal that commits after the check deletes the rows itself (forget()), because it runs after
     * these inserts. A withdrawal that commits before the check is seen by it, and the rows are deleted here.
     */
    public function store(): void {
        global $DB;

        if (!$this->pending) {
            return;
        }
        $sidelineid = (int) $this->sideline->id;
        $users = [];
        foreach ($this->pending as [$userid, $groupid, $cmid, $type]) {
            $row = (object) ['sidelineid' => $sidelineid, 'userid' => $userid, 'groupid' => $groupid, 'cmid' => $cmid,
                'eventtype' => $type, 'daystart' => $this->daystart];
            if ($DB->is_transaction_started()) {
                // Inside a transaction a failed insert would abort it on PostgreSQL: look first.
                if (!$DB->record_exists('sideline_daycount', (array) $row)) {
                    $DB->insert_record('sideline_daycount', $row, false);
                }
            } else {
                try {
                    $DB->insert_record('sideline_daycount', $row, false);
                } catch (\dml_write_exception $e) {
                    // A parallel request stored the same row: the unique index keeps one.
                    if (!$DB->record_exists('sideline_daycount', (array) $row)) {
                        throw $e;
                    }
                }
            }
            $users[$userid] = $userid;
        }
        $this->pending = [];
        $DB->delete_records_select('sideline_daycount', 'sidelineid = ? AND daystart < ?', [$sidelineid, $this->daystart]);

        $users = array_values($users);
        $allowed = array_intersect(
            array_keys(sharing_manager::sharing_users($this->sideline, $users)),
            consent_manager::users_allowing_processing($this->sideline, $users)
        );
        $gone = array_values(array_diff($users, $allowed));
        if ($gone) {
            self::forget($sidelineid, $gone);
        }
    }

    /**
     * Load the rows of the day for the groups of the statements, once.
     */
    private function load(): void {
        global $DB;

        if ($this->rows !== null) {
            return;
        }
        $this->rows = [];
        if (!$this->groupids) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($this->groupids, SQL_PARAMS_NAMED);
        // One query, not a recordset: PostgreSQL opens a recordset as a cursor, which costs three round trips, and
        // the rows of one day are few (people counted in the statements tried that day).
        $rows = $DB->get_records_select(
            'sideline_daycount',
            "sidelineid = :sid AND daystart = :day AND groupid $insql",
            $params + ['sid' => $this->sideline->id, 'day' => $this->daystart],
            '',
            'id, userid, groupid, cmid, eventtype'
        );
        foreach ($rows as $row) {
            $this->rows[$row->groupid . '_' . $row->cmid . '_' . $row->eventtype][(int) $row->userid] = true;
        }
    }

    /**
     * Delete the day counts of people in one instance: they stopped sharing or allowing processing, or a
     * privacy request asks for it.
     *
     * @param int $sidelineid Instance id.
     * @param int[] $userids Users.
     */
    public static function forget(int $sidelineid, array $userids): void {
        global $DB;

        $userids = array_values(array_unique(array_map('intval', $userids)));
        if (!$userids) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $DB->delete_records_select('sideline_daycount', "sidelineid = :sid AND userid $insql", $params + ['sid' => $sidelineid]);
    }

    /**
     * Delete every day count of an instance: its sharing terms changed, group mode ended, or it is deleted.
     *
     * @param int $sidelineid Instance id.
     */
    public static function forget_instance(int $sidelineid): void {
        global $DB;

        $DB->delete_records('sideline_daycount', ['sidelineid' => $sidelineid]);
    }

    /**
     * Delete every day count of an instance if the terms its people shared under changed: a wider circle or
     * level (group version), a new consent version, or another sharing mode. Whoever shares again under the new
     * terms is then counted from the current data only.
     *
     * @param \stdClass $before Instance record before the change.
     * @param \stdClass $after Instance record after the change.
     * @return bool Whether the counts were deleted.
     */
    public static function forget_if_terms_changed(\stdClass $before, \stdClass $after): bool {
        foreach (['groupversion', 'consentversion', 'sharingmode'] as $field) {
            if ((string) ($after->$field ?? $before->$field ?? '') !== (string) ($before->$field ?? '')) {
                self::forget_instance((int) $before->id);
                return true;
            }
        }
        return false;
    }

    /**
     * Delete the day counts of a group in every instance: the group was deleted, and its members leave it without
     * an event of their own.
     *
     * @param int $groupid Group id.
     */
    public static function forget_group(int $groupid): void {
        global $DB;

        $DB->delete_records('sideline_daycount', ['groupid' => $groupid]);
    }

    /**
     * Delete a person's day counts in every instance of a course: they left a group or the course.
     *
     * @param int $courseid Course id.
     * @param int $userid User id.
     */
    public static function forget_course_user(int $courseid, int $userid): void {
        global $DB;

        $DB->delete_records_select(
            'sideline_daycount',
            'userid = :userid AND sidelineid IN (SELECT id FROM {sideline} WHERE course = :courseid)',
            ['userid' => $userid, 'courseid' => $courseid]
        );
    }

    /**
     * Retention: delete the rows of earlier days of every instance.
     *
     * @param int $now Current time.
     * @return int Start of the day; rows before it are gone.
     */
    public static function purge(int $now): int {
        global $DB;

        $daystart = group_service::day_start($now);
        $DB->delete_records_select('sideline_daycount', 'daystart < ?', [$daystart]);
        return $daystart;
    }
}
