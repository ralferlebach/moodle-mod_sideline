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
 * Prepares privacy-checked group statements for one recipient (G2 to G4, P-G01, P-G02, P-G04).
 *
 * Decisions taken with the product owner: the open mode stays inside the course; events of other
 * people are published only after their active opt-in; only completions are used, never failed
 * attempts or other negative events; anonymous statements need at least groupminsize persons,
 * otherwise they are suppressed. No exact times are ever published, only "recently".
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
    /** @var int What counts as "recently". */
    public const RECENT = WEEKSECS;
    /** @var int Upper bound of names in one statement. */
    public const MAX_NAMES = 3;
    /** @var int Upper bound of statements per commentary. */
    public const MAX_STATEMENTS = 1;

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

        $members = [];
        foreach ($circles as $circle) {
            foreach ($circle['members'] as $userid) {
                $members[$userid] = true;
            }
        }
        unset($members[$recipientid]);
        $sharing = sharing_manager::sharing_users($sideline, array_keys($members));
        if (!$sharing) {
            return [[], []];
        }

        $completions = $this->recent_completions(array_keys($visiblecms), array_keys($sharing), $now);
        $level = (string) $sideline->grouppublish;
        $minsize = max(2, (int) $sideline->groupminsize);

        $statements = [];
        $contributors = [];
        foreach ($circles as $circle) {
            foreach ($visiblecms as $cmid => $cm) {
                $people = array_values(array_intersect($completions[$cmid] ?? [], $circle['members']));
                $people = array_values(array_filter($people, static fn(int $u): bool => isset($sharing[$u])));
                if (!$people) {
                    continue;
                }
                $statement = $this->statement($circle, $cm, $people, $sharing, $level, $minsize);
                if ($statement) {
                    $statements[] = $statement;
                    $contributors = array_merge($contributors, $statement['contributors']);
                }
                if (count($statements) >= self::MAX_STATEMENTS) {
                    break 2;
                }
            }
        }
        foreach ($statements as &$statement) {
            unset($statement['contributors']);
        }
        return [$statements, array_values(array_unique($contributors))];
    }

    /**
     * Build one statement at the strongest level the data and the decisions allow, or none.
     *
     * @param array $circle Circle: own (bool), groupname (string|null), members.
     * @param \cm_info $cm Activity.
     * @param int[] $people Sharing people who recently completed it.
     * @param array $sharing User id => named consent.
     * @param string $level Instance maximum level.
     * @param int $minsize Smallest anonymous aggregate.
     * @return array|null
     */
    private function statement(array $circle, \cm_info $cm, array $people, array $sharing, string $level, int $minsize): ?array {
        $label = clean_param(format_string($cm->name, true, ['context' => $cm->context]), PARAM_NOTAGS);
        $named = array_values(array_filter($people, static fn(int $u): bool => !empty($sharing[$u])));
        $base = ['cmid' => (int) $cm->id, 'label' => $label];

        if (in_array($level, ['named', 'named_with_group'], true) && $named) {
            $names = $this->names(array_slice($named, 0, self::MAX_NAMES));
            if ($circle['own']) {
                return $base + [
                    'key' => 'group_named',
                    'names' => $names,
                    'contributors' => array_slice($named, 0, self::MAX_NAMES),
                ];
            }
            if ($level === 'named_with_group' && $circle['groupname'] !== null) {
                return $base + ['key' => 'group_named_with_group', 'names' => $names, 'group' => $circle['groupname'],
                    'contributors' => array_slice($named, 0, self::MAX_NAMES)];
            }
        }
        // Anonymous statements: never about fewer than minsize people (P-G03, small aggregates).
        if (count($people) < $minsize) {
            return null;
        }
        if (!$circle['own'] && in_array($level, ['group_only', 'named_with_group'], true) && $circle['groupname'] !== null) {
            return $base + [
                'key' => 'group_only',
                'count' => count($people),
                'group' => $circle['groupname'],
                'contributors' => $people,
            ];
        }
        return $base + ['key' => $circle['own'] ? 'group_together' : 'group_others', 'count' => count($people),
            'contributors' => $people];
    }

    /**
     * The recipient circles: own admitted groups and, in open mode, other admitted public groups.
     *
     * @param \stdClass $sideline Instance record.
     * @param int $recipientid Recipient.
     * @param int $courseid Course id.
     * @return array[] own, groupname, members (active enrolled user ids).
     */
    private function circles(\stdClass $sideline, int $recipientid, int $courseid): array {
        $admitted = instance_config::admitted_group_ids((int) $sideline->id, $courseid);
        $own = array_map('intval', groups_get_user_groups($courseid, $recipientid)[0] ?? []);
        $all = groups_get_all_groups($courseid);
        $context = \context_course::instance($courseid);
        $active = array_map('intval', array_keys(get_enrolled_users($context, '', 0, 'u.id', null, 0, 0, true)));

        $circles = [];
        foreach ($all as $group) {
            $gid = (int) $group->id;
            if ($admitted !== null && !in_array($gid, $admitted, true)) {
                continue;
            }
            $visibility = (int) ($group->visibility ?? GROUPS_VISIBILITY_ALL);
            $isown = in_array($gid, $own, true);
            if ($isown) {
                // Members may only learn about each other if membership is visible to members.
                if (!in_array($visibility, [GROUPS_VISIBILITY_ALL, GROUPS_VISIBILITY_MEMBERS], true)) {
                    continue;
                }
            } else {
                if ($sideline->sharingmode !== instance_config::GROUP_OPEN || $visibility !== GROUPS_VISIBILITY_ALL) {
                    continue;
                }
            }
            $members = array_map('intval', array_keys(groups_get_members($gid, 'u.id')));
            $members = array_values(array_intersect($members, $active));
            if (!$members) {
                continue;
            }
            $circles[] = [
                'own' => $isown,
                'groupname' => $isown ? null : clean_param(
                    format_string($group->name, true, ['context' => $context]),
                    PARAM_NOTAGS,
                ),
                'members' => $members,
            ];
        }
        // Own groups first: they are the closest circle.
        usort($circles, static fn(array $a, array $b): int => (int) $b['own'] <=> (int) $a['own']);
        return $circles;
    }

    /**
     * Recent positive completions of sharing users, in one query.
     *
     * @param int[] $cmids Course module ids.
     * @param int[] $userids Sharing users.
     * @param int $now Current time.
     * @return array cmid => user ids.
     */
    private function recent_completions(array $cmids, array $userids, int $now): array {
        global $DB;

        [$cmsql, $cmparams] = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED, 'cm');
        [$usql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
        // Completed or completed-pass only: failed attempts are never published (decision 3).
        $rs = $DB->get_recordset_sql(
            "SELECT id, coursemoduleid, userid FROM {course_modules_completion}
              WHERE coursemoduleid $cmsql AND userid $usql
                AND completionstate IN (:complete, :pass) AND timemodified >= :since",
            $cmparams + $uparams + [
                'complete' => COMPLETION_COMPLETE,
                'pass' => COMPLETION_COMPLETE_PASS,
                'since' => $now - self::RECENT,
            ]
        );
        $result = [];
        foreach ($rs as $row) {
            $result[(int) $row->coursemoduleid][] = (int) $row->userid;
        }
        $rs->close();
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
