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

use mod_sideline\local\instance_config;

/**
 * Prepares privacy-checked group statements for one recipient (G2 to G4, P-G01 to P-G04).
 *
 * Decisions taken with the product owner: the open mode stays inside the course; events of other
 * people are published only after their active opt-in; only positive event types are used, never
 * failed attempts, grades or other negative events. No exact times are ever published.
 *
 * Event types, configured per instance: "viewed" (the activity was opened), "completed"
 * (it counts as completed), "passed" (completed with a pass grade). An activity whose completion is
 * not tracked has an unknown state for "completed" and "passed": nothing is said about it.
 *
 * Protection of aggregates (docs/privacy/GRUPPENAGGREGATE.md):
 * - Anonymous statements need at least groupminsize persons (never fewer than three) and give a
 *   coarse band ("a few", "several", "many") instead of an exact number.
 * - For anonymous statements the time window ends at the start of the current day: the statement
 *   changes at most once a day, so comparing two visits does not reveal who has just done something.
 *   Named statements are about people who agreed to be named, so their window ends now.
 * - Only one statement per commentary, and only about activities the recipient can see.
 *
 * Cost: one query for the recipient's groups, one for all groups of the course, and one
 * query per configured event type that returns only sharing, consenting, actively enrolled members
 * of the admitted circles with an event in the window. No query per group or per member.
 *
 * What a recipient may learn:
 * - Activities: only activities the recipient can see (the recipient's own scope).
 * - Closed mode: members of the recipient's own admitted groups whose membership is visible to
 *   members (GROUPS_VISIBILITY_ALL or _MEMBERS).
 * - Open mode: additionally members of other admitted groups whose membership is visible to
 *   everybody (GROUPS_VISIBILITY_ALL).
 * - Names: only of people who opted in to name display, and only if the instance level allows it.
 * - Group names: only in open mode, only for groups visible to everybody, and only if the level allows it.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class group_service {
    /** @var day_count|null Day counts of the current facts() call. */
    private ?day_count $daycount = null;

    /** @var int Length of the window before the start of the current day. */
    public const RECENT = WEEKSECS;
    /** @var int Upper bound of names in one statement. */
    public const MAX_NAMES = 3;
    /** @var int Upper bound of statements per commentary. */
    public const MAX_STATEMENTS = 1;
    /** @var string[] Event types in the order in which they are told: the most specific first. */
    public const EVENT_ORDER = ['passed', 'completed', 'viewed'];

    /**
     * Group statements for a recipient.
     *
     * @param \stdClass $sideline Instance record.
     * @param int $recipientid Recipient user id.
     * @param \cm_info[] $cms Activities the recipient may see (their scope).
     * @param int $now Current time.
     * @return array [statements, contributor user ids]
     */
    public function facts(\stdClass $sideline, int $recipientid, array $cms, int $now): array {
        if (($sideline->sharingmode ?? instance_config::GROUP_INDIVIDUAL) === instance_config::GROUP_INDIVIDUAL) {
            return [[], []];
        }
        $courseid = (int) $sideline->course;
        // Only learners receive group statements. Teachers and managers never do: a statement would
        // tell them who opted in to sharing (L-16, P-40). The recipient must also be a member of an
        // admitted group whose membership is visible to members; open mode only adds further groups.
        $cm = get_coursemodule_from_instance('sideline', $sideline->id, $courseid, false, MUST_EXIST);
        if (
            !has_capability('mod/sideline:receivegroupstatements', \context_module::instance($cm->id), $recipientid)
                || has_capability('mod/sideline:manage', \context_module::instance($cm->id), $recipientid)
        ) {
            return [[], []];
        }
        $circles = $this->circles($sideline, $recipientid, $courseid);
        if (!array_filter($circles, static fn(array $c): bool => $c['own'])) {
            return [[], []];
        }

        // Only activities of the instance's course that the recipient can actually see and open.
        $visiblecms = array_filter($cms, static fn(\cm_info $cm): bool => (int) $cm->course === $courseid && $cm->uservisible);
        if (!$visiblecms) {
            return [[], []];
        }

        // Named statements: the last RECENT seconds. Anonymous statements: a window of the same length that
        // ends at the start of today, stable within the day.
        $daystart = self::day_start($now);
        $types = array_values(array_intersect(self::EVENT_ORDER, instance_config::group_events($sideline)));
        $events = $this->events(
            $sideline,
            array_keys($visiblecms),
            array_keys($circles),
            $types,
            min($now, $daystart) - self::RECENT,
            $now + 1,
            $recipientid
        );
        if (!$events) {
            return [[], []];
        }
        $level = (string) $sideline->grouppublish;
        $minsize = max(instance_config::MIN_GROUP_SIZE, (int) $sideline->groupminsize);

        $statements = [];
        $contributors = [];
        $this->daycount = new day_count($sideline, $daystart, array_keys($circles));
        foreach ($circles as $groupid => $circle) {
            foreach ($visiblecms as $cmid => $cm) {
                foreach ($types as $type) {
                    $people = $events[$groupid][$cmid][$type] ?? [];
                    if (!$people) {
                        continue;
                    }
                    $statement = $this->statement($circle, $cm, $type, $people, $level, $minsize, $daystart, $now);
                    if ($statement) {
                        $statements[] = $statement;
                        $contributors = array_merge($contributors, $statement['contributors']);
                        break;
                    }
                }
                if (count($statements) >= self::MAX_STATEMENTS) {
                    break 2;
                }
            }
        }
        $this->daycount->store();
        $this->daycount = null;
        foreach ($statements as &$statement) {
            unset($statement['contributors']);
        }
        return [$statements, array_values(array_unique($contributors))];
    }

    /**
     * Start of the day of a moment, in the server's time zone.
     *
     * @param int $now Unix time.
     * @return int
     */
    public static function day_start(int $now): int {
        return usergetmidnight($now, \core_date::get_server_timezone());
    }

    /**
     * The coarse band of a number of people, never the number itself.
     *
     * @param int $count Number of people, at least the minimum size.
     * @return string few, several or many.
     */
    public static function band(int $count): string {
        if ($count >= 12) {
            return 'many';
        }
        return $count >= 6 ? 'several' : 'few';
    }

    /**
     * Build one statement at the strongest level the data and the decisions allow, or none.
     *
     * @param array $circle Circle: own (bool), groupname (string|null).
     * @param \cm_info $cm Activity.
     * @param string $type Event type.
     * @param array $people User id => [named consent (bool), time of the event], sharing members with this event.
     * @param string $level Instance maximum level.
     * @param int $minsize Smallest anonymous aggregate.
     * @param int $daystart Start of today: the end of the anonymous window.
     * @param int $now Current time.
     * @return array|null
     */
    private function statement(
        array $circle,
        \cm_info $cm,
        string $type,
        array $people,
        string $level,
        int $minsize,
        int $daystart,
        int $now,
    ): ?array {
        $label = clean_param(format_string($cm->name, true, ['context' => $cm->context]), PARAM_NOTAGS);
        $recent = array_filter($people, static fn(array $p): bool => $p['time'] >= $now - self::RECENT);
        $named = array_keys(array_filter($recent, static fn(array $p): bool => $p['named']));
        sort($named);
        $base = ['cmid' => (int) $cm->id, 'label' => $label, 'event' => $type];
        if ($type === 'passed') {
            $base['outcome'] = 'passed';
        }

        if (in_array($level, ['named', 'named_with_group'], true) && $named) {
            $chosen = array_slice($named, 0, self::MAX_NAMES);
            if ($circle['own']) {
                return $base + ['key' => 'group_named', 'names' => $this->names($chosen), 'contributors' => $chosen];
            }
            if ($level === 'named_with_group' && $circle['groupname'] !== null) {
                return $base + ['key' => 'group_named_with_group', 'names' => $this->names($chosen),
                    'group' => $circle['groupname'], 'contributors' => $chosen];
            }
        }
        // Anonymous statements: never about fewer than minsize people, never with an exact number, and only
        // with events before today, so that two visits on one day see the same statement. The people counted
        // are fixed for the day when they are first counted: an event that Moodle stamps again today (a
        // completion ticked off and on) does not move a person out of the count. Who no longer shares, or
        // no longer has the event, drops out at once.
        $ids = $this->daycount->counted(
            (int) ($circle['id'] ?? 0),
            (int) $cm->id,
            $type,
            array_keys(array_filter(
                $people,
                static fn(array $p): bool => $p['time'] < $daystart && $p['time'] >= $daystart - self::RECENT
            )),
            array_keys($people)
        );
        if (count($ids) < $minsize) {
            return null;
        }
        // A statement about every other member of the group would tell the recipient what each of them did.
        if (isset($circle['others']) && count($ids) >= $circle['others']) {
            return null;
        }
        $band = self::band(count($ids));
        if (!$circle['own'] && in_array($level, ['group_only', 'named_with_group'], true) && $circle['groupname'] !== null) {
            return $base + ['key' => 'group_only', 'band' => $band, 'group' => $circle['groupname'], 'contributors' => $ids];
        }
        return $base + ['key' => $circle['own'] ? 'group_together' : 'group_others', 'band' => $band, 'contributors' => $ids];
    }

    /**
     * The recipient circles: own admitted groups and, in open mode, other admitted public groups.
     *
     * @param \stdClass $sideline Instance record.
     * @param int $recipientid Recipient.
     * @param int $courseid Course id.
     * @return array[] Group id => own (bool), groupname (string|null), others (int, members without the
     *     recipient); own groups first.
     */
    private function circles(\stdClass $sideline, int $recipientid, int $courseid): array {
        $admitted = instance_config::admitted_group_ids((int) $sideline->id, $courseid);
        $own = array_map('intval', groups_get_user_groups($courseid, $recipientid)[0] ?? []);
        $context = \context_course::instance($courseid);

        $owncircles = [];
        $othercircles = [];
        foreach (groups_get_all_groups($courseid) as $group) {
            $gid = (int) $group->id;
            if ($admitted !== null && !in_array($gid, $admitted, true)) {
                continue;
            }
            $visibility = (int) ($group->visibility ?? GROUPS_VISIBILITY_ALL);
            if (in_array($gid, $own, true)) {
                // Members may only learn about each other if membership is visible to members.
                if (in_array($visibility, [GROUPS_VISIBILITY_ALL, GROUPS_VISIBILITY_MEMBERS], true)) {
                    $owncircles[$gid] = ['id' => $gid, 'own' => true, 'groupname' => null];
                }
            } else if ($sideline->sharingmode === instance_config::GROUP_OPEN && $visibility === GROUPS_VISIBILITY_ALL) {
                $othercircles[$gid] = ['id' => $gid, 'own' => false, 'groupname' => clean_param(
                    format_string($group->name, true, ['context' => $context]),
                    PARAM_NOTAGS,
                )];
            }
        }
        $circles = $owncircles + $othercircles;
        if ($circles) {
            // How many other people each circle has: an anonymous statement may never cover all of them.
            global $DB;
            [$insql, $params] = $DB->get_in_or_equal(array_keys($circles), SQL_PARAMS_NAMED);
            $counts = $DB->get_records_sql_menu(
                "SELECT groupid, COUNT(userid)
                   FROM {groups_members}
                  WHERE groupid $insql AND userid <> :recipient
               GROUP BY groupid",
                $params + ['recipient' => $recipientid]
            );
            foreach ($circles as $gid => &$circle) {
                $circle['others'] = (int) ($counts[$gid] ?? 0);
            }
            unset($circle);
        }
        return $circles;
    }

    /**
     * Events of sharing members of the circles, set-based.
     *
     * One query per event type. It returns only members who are actively enrolled, publish under the
     * current group version, whose consent allows processing, and who had the event in the window.
     *
     * @param \stdClass $sideline Instance record.
     * @param int[] $cmids Course module ids.
     * @param int[] $groupids Circle group ids.
     * @param string[] $types Event types.
     * @param int $since Start of the window.
     * @param int $until End of the window (exclusive).
     * @param int $recipientid The recipient, never part of a statement about others.
     * @return array groupid => cmid => type => [user id => [named (bool), time (int)]].
     */
    private function events(
        \stdClass $sideline,
        array $cmids,
        array $groupids,
        array $types,
        int $since,
        int $until,
        int $recipientid,
    ): array {
        global $DB;

        if (!$cmids || !$groupids || !$types) {
            return [];
        }
        [$cmsql, $cmparams] = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED, 'cm');
        [$gsql, $gparams] = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED, 'g');
        [$esql, $eparams] = get_enrolled_sql(\context_course::instance((int) $sideline->course), '', 0, true);
        [$consentsql, $consentparams] = \mod_sideline\local\consent\consent_manager::processing_sql($sideline, 'c');
        $base = "FROM {groups_members} gm
                 JOIN ($esql) je ON je.id = gm.userid
                 JOIN {sideline_publish} p ON p.userid = gm.userid AND p.sidelineid = :sid
                      AND p.publish = 1 AND p.infoversion >= :version
            LEFT JOIN {sideline_consent} c ON c.userid = gm.userid AND c.sidelineid = :csid";
        $params = $cmparams + $gparams + $eparams + $consentparams + [
            'sid' => $sideline->id,
            'csid' => $sideline->id,
            'version' => (int) $sideline->groupversion,
            'recipient' => $recipientid,
            'since' => $since,
            'until' => $until,
        ];
        $where = "gm.groupid $gsql AND gm.userid <> :recipient AND $consentsql";
        $queries = [
            'completed' => "SELECT gm.id, gm.groupid, gm.userid, x.coursemoduleid, x.timemodified AS eventtime, p.named $base
                              JOIN {course_modules_completion} x ON x.userid = gm.userid
                             WHERE $where AND x.coursemoduleid $cmsql AND x.completionstate IN (:complete, :pass)
                                   AND x.timemodified >= :since AND x.timemodified < :until",
            'passed' => "SELECT gm.id, gm.groupid, gm.userid, x.coursemoduleid, x.timemodified AS eventtime, p.named $base
                           JOIN {course_modules_completion} x ON x.userid = gm.userid
                          WHERE $where AND x.coursemoduleid $cmsql AND x.completionstate = :pass
                                AND x.timemodified >= :since AND x.timemodified < :until",
            'viewed' => "SELECT gm.id, gm.groupid, gm.userid, x.coursemoduleid, x.timecreated AS eventtime, p.named $base
                           JOIN {course_modules_viewed} x ON x.userid = gm.userid
                          WHERE $where AND x.coursemoduleid $cmsql
                                AND x.timecreated >= :since AND x.timecreated < :until",
        ];
        $params += ['complete' => COMPLETION_COMPLETE, 'pass' => COMPLETION_COMPLETE_PASS];

        $result = [];
        foreach ($types as $type) {
            $sql = $queries[$type];
            // Named parameters must all be used: drop the ones a query does not contain.
            $used = array_filter($params, static fn(string $name): bool => str_contains($sql, ':' . $name), ARRAY_FILTER_USE_KEY);
            $rs = $DB->get_recordset_sql($sql, $used);
            foreach ($rs as $row) {
                $result[(int) $row->groupid][(int) $row->coursemoduleid][$type][(int) $row->userid] = [
                    'named' => (bool) $row->named,
                    'time' => (int) $row->eventtime,
                ];
            }
            $rs->close();
        }
        return $result;
    }

    /**
     * First names of consenting users, for local resolution only.
     *
     * @param int[] $userids Users.
     * @return string[]
     */
    private function names(array $userids): array {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal($userids);
        $names = $DB->get_records_select_menu('user', "id $insql AND deleted = 0", $params, '', 'id, firstname');
        $result = [];
        foreach ($userids as $id) {
            if (isset($names[$id])) {
                $result[] = clean_param($names[$id], PARAM_NOTAGS);
            }
        }
        return $result;
    }

    /**
     * Whether all contributors still share, right before storing or delivering (L-G06 race).
     *
     * @param \stdClass $sideline Instance record.
     * @param int[] $contributors Contributors.
     * @param bool $namedneeded Whether names are part of the text.
     * @return bool
     */
    public function contributors_still_share(\stdClass $sideline, array $contributors, bool $namedneeded): bool {
        if (!$contributors) {
            return true;
        }
        $sharing = sharing_manager::sharing_users($sideline, $contributors);
        foreach ($contributors as $userid) {
            if (!array_key_exists($userid, $sharing) || ($namedneeded && !$sharing[$userid])) {
                return false;
            }
        }
        return true;
    }
}
