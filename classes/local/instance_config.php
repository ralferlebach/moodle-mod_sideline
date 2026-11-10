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

namespace mod_sideline\local;

use mod_sideline\local\ai\persona_registry;

/**
 * Normalisation and persistence helpers for the configuration of one Sideline instance.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class instance_config {
    /** @var string Observe every activity of the course. */
    public const SCOPE_COURSE = 'course';
    /** @var string Observe the activities of one course section. */
    public const SCOPE_SECTION = 'section';
    /** @var string Observe an explicit list of activities. */
    public const SCOPE_ACTIVITIES = 'activities';

    /** @var string Show the panel directly on the course page. */
    public const DISPLAY_INLINE = 'inline';
    /** @var string Show the panel on the activity's own page. */
    public const DISPLAY_PAGE = 'page';

    /** @var string Group mode: only the learner's own events (default, L-G01). */
    public const GROUP_INDIVIDUAL = 'individual';
    /** @var string Group mode: plus events of members of the learner's own admitted groups (L-G02). */
    public const GROUP_CLOSED = 'closed';
    /** @var string Group mode: plus events of other explicitly admitted groups (L-G03). */
    public const GROUP_OPEN = 'open';

    /** @var string[] Publication levels in increasing order of disclosure (P-G02). */
    public const PUBLISH_LEVELS = ['general', 'group_only', 'named', 'named_with_group'];

    /** @var int Upper bound of further courses in a course union. */
    public const MAX_SOURCES = 10;

    /** @var int Upper bound of course branches (categories) of a course union. */
    public const MAX_SOURCE_CATEGORIES = 5;

    /** @var int Upper bound of courses observed for one learner through course branches (N-02). */
    public const MAX_BRANCH_COURSES = 20;

    /**
     * @var string[] Event types that group mode may publish, none of them negative: opened the activity (access),
     * worked in it (use: own changes such as a submission, a post or an attempt), completed it, passed it, and
     * improved a result in it (a higher grade than before; never the grade, never a lower one).
     */
    public const GROUP_EVENTS = ['viewed', 'active', 'completed', 'passed', 'improved'];

    /** @var string[] Event types that are only ever told anonymously, about a group of at least the minimum size. */
    public const GROUP_EVENTS_ANONYMOUS = ['improved'];

    /** @var int[] Time windows of group statements, in seconds. */
    public const GROUP_WINDOWS = [DAYSECS, 3 * DAYSECS, WEEKSECS, 2 * WEEKSECS, 4 * WEEKSECS];

    /** @var int Smallest anonymous aggregate (: two people are never enough). */
    public const MIN_GROUP_SIZE = 3;

    /**
     * Bring submitted form data into the shape the table expects.
     *
     * @param \stdClass $data Form data.
     * @return \stdClass
     */
    public static function normalise(\stdClass $data): \stdClass {
        if (!in_array($data->scopetype ?? '', [self::SCOPE_COURSE, self::SCOPE_SECTION, self::SCOPE_ACTIVITIES], true)) {
            $data->scopetype = self::SCOPE_COURSE;
        }
        $data->scopesection = max(0, (int) ($data->scopesection ?? 0));
        foreach (['aienabled', 'typing', 'consentrequired', 'personachoice', 'dialogenabled'] as $flag) {
            $data->$flag = empty($data->$flag) ? 0 : 1;
        }
        $data->scopesubsections = !isset($data->scopesubsections) || !empty($data->scopesubsections) ? 1 : 0;
        if (!in_array($data->display ?? '', [self::DISPLAY_INLINE, self::DISPLAY_PAGE], true)) {
            $data->display = self::DISPLAY_INLINE;
        }

        // Personas: the offered subset must contain the default; unknown keys are dropped.
        $offered = $data->personas ?? [];
        if (is_string($offered)) {
            $offered = $offered === '' ? [] : explode(',', $offered);
        }
        $valid = persona_registry::keys();
        $offered = array_values(array_intersect(array_unique(array_map('strval', $offered)), $valid));
        if (!in_array($data->persona ?? '', $valid, true)) {
            $data->persona = $offered[0] ?? persona_registry::DEFAULT_KEY;
        }
        if (!in_array($data->persona, $offered, true)) {
            array_unshift($offered, $data->persona);
        }
        $data->personas = implode(',', $offered);

        $data->dialogcooldown = max(0, (int) ($data->dialogcooldown ?? DAYSECS));

        if (!in_array($data->sharingmode ?? '', [self::GROUP_INDIVIDUAL, self::GROUP_CLOSED, self::GROUP_OPEN], true)) {
            $data->sharingmode = self::GROUP_INDIVIDUAL;
        }
        $levels = $data->sharingmode === self::GROUP_OPEN ? ['general', 'group_only', 'named_with_group'] : ['general', 'named'];
        if (!in_array($data->grouppublish ?? '', $levels, true)) {
            $data->grouppublish = 'general';
        }
        $data->groupminsize = min(50, max(self::MIN_GROUP_SIZE, (int) ($data->groupminsize ?? 3)));
        $data->groupevents = implode(',', self::group_events($data));
        $data->groupwindow = self::group_window($data);
        return $data;
    }

    /**
     * Raise the information versions when a change needs new decisions of learners (P-45, L-G06).
     *
     * Switching the consent gate on makes earlier acceptances void. Any widening of group sharing
     * (higher publication level, open groups, smaller anonymous aggregates) makes earlier sharing
     * opt-ins void. Narrowing applies at once without new decisions. Used by the settings form and
     * by the configuration import alike, so no path can widen sharing silently.
     *
     * @param \stdClass $old Stored instance record.
     * @param \stdClass $new New values; consentversion and groupversion are set on it.
     */
    public static function apply_version_bumps(\stdClass $old, \stdClass $new): void {
        $new->consentversion = (int) $old->consentversion;
        if (!empty($new->consentrequired) && empty($old->consentrequired)) {
            $new->consentversion++;
        }
        $levels = self::PUBLISH_LEVELS;
        $modes = [self::GROUP_INDIVIDUAL => 0, self::GROUP_CLOSED => 1, self::GROUP_OPEN => 2];
        $wider = array_search($new->grouppublish, $levels) > array_search($old->grouppublish, $levels)
            || ($modes[$new->sharingmode] ?? 0) > ($modes[$old->sharingmode] ?? 0)
            || (int) $new->groupminsize < (int) $old->groupminsize;
        // A new event type, or a longer time window, tells recipients more about the same people.
        $wider = $wider || array_diff(self::group_events($new), self::group_events($old))
            || self::group_window($new) > self::group_window($old);
        $new->groupversion = (int) $old->groupversion + ($wider ? 1 : 0);
    }

    /**
     * The event types published in group mode, as configured (only known types, default: completions).
     *
     * @param \stdClass $sideline Instance record or form data.
     * @return string[]
     */
    public static function group_events(\stdClass $sideline): array {
        $value = $sideline->groupevents ?? 'completed';
        $types = is_array($value) ? $value : explode(',', (string) $value);
        $types = array_values(array_intersect(self::GROUP_EVENTS, array_map('trim', array_map('strval', $types))));
        return $types ?: ['completed'];
    }

    /**
     * The time window of group statements, one of GROUP_WINDOWS (default: a week).
     *
     * @param \stdClass $sideline Instance record or form data.
     * @return int Seconds.
     */
    public static function group_window(\stdClass $sideline): int {
        $window = (int) ($sideline->groupwindow ?? WEEKSECS);
        return in_array($window, self::GROUP_WINDOWS, true) ? $window : WEEKSECS;
    }

    /**
     * Which configured event types have no data on this site: the standard log is off (use) or the grade history
     * is off (improved results). These are never stated; the settings and the trace name them as unknown, so that
     * no silence is read as "nobody did it".
     *
     * @param \stdClass $sideline Instance record.
     * @return string[]
     */
    public static function unknown_group_events(\stdClass $sideline): array {
        global $CFG;

        $unknown = [];
        $types = self::group_events($sideline);
        if (in_array('active', $types, true)) {
            $stores = explode(',', (string) get_config('tool_log', 'enabled_stores'));
            if (!in_array('logstore_standard', $stores, true)) {
                $unknown[] = 'active';
            }
        }
        if (in_array('improved', $types, true) && !empty($CFG->disablegradehistory)) {
            $unknown[] = 'improved';
        }
        return $unknown;
    }

    /**
     * The release circle of the group mode: which groups may be recipients or sources.
     *
     * Every admitted group whose membership is visible to members ("m") can be a learner's own circle;
     * in open mode every admitted group visible to everybody ("p") can be another circle. The circle is
     * the set of these entries; learners decide about publishing for exactly this circle.
     *
     * @param \stdClass $sideline Instance record (id, course, sharingmode).
     * @return string[] Sorted entries "groupid:m" and "groupid:p".
     */
    public static function group_circle(\stdClass $sideline): array {
        if (($sideline->sharingmode ?? self::GROUP_INDIVIDUAL) === self::GROUP_INDIVIDUAL) {
            return [];
        }
        $admitted = self::admitted_group_ids((int) $sideline->id, (int) $sideline->course);
        $entries = [];
        foreach (groups_get_all_groups((int) $sideline->course) as $group) {
            $gid = (int) $group->id;
            if ($admitted !== null && !in_array($gid, $admitted, true)) {
                continue;
            }
            $visibility = (int) ($group->visibility ?? GROUPS_VISIBILITY_ALL);
            if (in_array($visibility, [GROUPS_VISIBILITY_ALL, GROUPS_VISIBILITY_MEMBERS], true)) {
                $entries[] = $gid . ':m';
            }
            if ($sideline->sharingmode === self::GROUP_OPEN && $visibility === GROUPS_VISIBILITY_ALL) {
                $entries[] = $gid . ':p';
            }
        }
        sort($entries);
        return $entries;
    }

    /**
     * Compare the release circle with the stored one and raise the group version when it grew.
     *
     * Called after every change of admitted groups (form, import, restore) and by the observers of
     * group, grouping and visibility changes. Only a growth needs new decisions: a group that leaves the
     * circle narrows it, which applies at once. Either way the stored circle is updated and cached
     * texts of the instance are dropped when anything changed.
     *
     * @param int $sidelineid Instance id.
     * @return bool Whether the group version was raised.
     */
    public static function refresh_group_circle(int $sidelineid): bool {
        global $DB;

        $sideline = $DB->get_record('sideline', ['id' => $sidelineid]);
        if (!$sideline) {
            return false;
        }
        $old = json_decode((string) ($sideline->groupcircle ?? ''), true);
        $new = self::group_circle($sideline);
        if (is_array($old) && $old === $new) {
            return false;
        }
        // Without a stored circle (instances from before 0.4.0) the current one becomes the reference.
        $grown = is_array($old) && array_diff($new, $old);
        $update = (object) ['id' => $sidelineid, 'groupcircle' => json_encode($new)];
        if ($grown) {
            $update->groupversion = (int) $sideline->groupversion + 1;
        }
        $DB->update_record('sideline', $update);
        \mod_sideline\local\cache\commentary_cache::invalidate_instance($sidelineid);
        if ($grown) {
            // A wider circle: the day counts were made under the old one.
            \mod_sideline\local\group\day_count::forget_instance($sidelineid);
        }
        return (bool) $grown;
    }

    /**
     * Offered persona keys of an instance.
     *
     * @param \stdClass $sideline Instance record.
     * @return string[]
     */
    public static function offered_personas(\stdClass $sideline): array {
        $keys = array_filter(explode(',', (string) ($sideline->personas ?? '')));
        if (!in_array($sideline->persona, $keys, true)) {
            array_unshift($keys, $sideline->persona);
        }
        return array_values(array_unique($keys));
    }

    /**
     * Replace the explicit activity list of an instance.
     *
     * @param int $sidelineid Instance id.
     * @param int[] $cmids Course module ids.
     */
    public static function save_scope_activities(int $sidelineid, array $cmids): void {
        global $DB;

        $DB->delete_records('sideline_scope', ['sidelineid' => $sidelineid]);
        foreach (array_unique(array_map('intval', $cmids)) as $cmid) {
            if ($cmid > 0) {
                $DB->insert_record('sideline_scope', (object) ['sidelineid' => $sidelineid, 'cmid' => $cmid]);
            }
        }
    }

    /**
     * Read the explicit activity list of an instance.
     *
     * @param int $sidelineid Instance id.
     * @return int[]
     */
    public static function get_scope_activities(int $sidelineid): array {
        global $DB;

        return array_map('intval', $DB->get_fieldset_select('sideline_scope', 'cmid', 'sidelineid = ?', [$sidelineid]));
    }

    /**
     * Replace the further courses of a course union, as far as the editing user is concerned.
     *
     * The editor decides only about courses in which they may manage activities: those are added or
     * removed as submitted. Sources the editor cannot manage are not offered to them in the form and
     * stay as they are, so a co-teacher saving the settings does not drop a colleague's courses.
     * Each row records the teacher whose rights authorise it; scope_resolver rechecks them (P-43).
     *
     * @param \stdClass $sideline Instance record (needs id and course).
     * @param int[] $courseids Course ids.
     * @param int $editorid The user who edits the instance; each source course is checked for them.
     */
    public static function save_sources(\stdClass $sideline, array $courseids, int $editorid): void {
        global $DB;

        if (!get_config('mod_sideline', 'crosscourse')) {
            return;
        }
        $manages = static fn(int $courseid): bool => $DB->record_exists('course', ['id' => $courseid])
            && has_capability('moodle/course:manageactivities', \context_course::instance($courseid), $editorid);
        $wanted = [];
        foreach (array_unique(array_map('intval', $courseids)) as $courseid) {
            if ($courseid > 1 && $courseid !== (int) $sideline->course && $manages($courseid)) {
                $wanted[$courseid] = true;
            }
        }
        $existing = $DB->get_records('sideline_source', ['sidelineid' => $sideline->id], 'id', 'id, courseid');
        foreach ($existing as $row) {
            $courseid = (int) $row->courseid;
            if (isset($wanted[$courseid])) {
                $DB->set_field('sideline_source', 'usermodified', $editorid, ['id' => $row->id]);
                unset($wanted[$courseid]);
            } else if (!$DB->record_exists('course', ['id' => $courseid]) || $manages($courseid)) {
                // Removed by someone who may decide about it, or the course is gone.
                $DB->delete_records('sideline_source', ['id' => $row->id]);
            }
        }
        $count = $DB->count_records('sideline_source', ['sidelineid' => $sideline->id]);
        foreach (array_keys($wanted) as $courseid) {
            if ($count >= self::MAX_SOURCES) {
                break;
            }
            $DB->insert_record('sideline_source', (object) ['sidelineid' => $sideline->id, 'courseid' => $courseid,
                'usermodified' => $editorid]);
            $count++;
        }
    }

    /**
     * Further courses of a course union.
     *
     * @param int $sidelineid Instance id.
     * @return int[]
     */
    public static function get_sources(int $sidelineid): array {
        return array_keys(self::get_source_authorities($sidelineid));
    }

    /**
     * Number of course sources and course branches that wait for a teacher's confirmation.
     *
     * @param int $sidelineid Instance id.
     * @return int
     */
    public static function count_unconfirmed_sources(int $sidelineid): int {
        global $DB;

        return $DB->count_records('sideline_source', ['sidelineid' => $sidelineid, 'usermodified' => 0])
            + $DB->count_records('sideline_sourcecat', ['sidelineid' => $sidelineid, 'usermodified' => 0]);
    }

    /**
     * Further courses with the teacher whose rights authorise them (0: unconfirmed, not evaluated).
     *
     * @param int $sidelineid Instance id.
     * @return int[] Course id to user id.
     */
    public static function get_source_authorities(int $sidelineid): array {
        global $DB;

        if (!get_config('mod_sideline', 'crosscourse')) {
            return [];
        }
        $result = [];
        foreach ($DB->get_records('sideline_source', ['sidelineid' => $sidelineid], 'id', 'id, courseid, usermodified') as $row) {
            $result[(int) $row->courseid] = (int) $row->usermodified;
        }
        return $result;
    }

    /**
     * Replace the course branches (categories with all subcategories) of a course union (L-19).
     *
     * As for courses: the editor decides about the categories in which they may manage activities;
     * other branches stay. Each row records the authorising teacher, rechecked on every evaluation.
     *
     * @param \stdClass $sideline Instance record (needs id).
     * @param int[] $categoryids Category ids.
     * @param int $editorid The user who edits the instance.
     */
    public static function save_source_categories(\stdClass $sideline, array $categoryids, int $editorid): void {
        global $DB;

        if (!get_config('mod_sideline', 'crosscourse')) {
            return;
        }
        $manages = static fn(int $categoryid): bool => $DB->record_exists('course_categories', ['id' => $categoryid])
            && has_capability('moodle/course:manageactivities', \context_coursecat::instance($categoryid), $editorid);
        $wanted = [];
        foreach (array_unique(array_map('intval', $categoryids)) as $categoryid) {
            if ($categoryid > 0 && $manages($categoryid)) {
                $wanted[$categoryid] = true;
            }
        }
        foreach ($DB->get_records('sideline_sourcecat', ['sidelineid' => $sideline->id], 'id', 'id, categoryid') as $row) {
            $categoryid = (int) $row->categoryid;
            if (isset($wanted[$categoryid])) {
                $DB->set_field('sideline_sourcecat', 'usermodified', $editorid, ['id' => $row->id]);
                unset($wanted[$categoryid]);
            } else if (!$DB->record_exists('course_categories', ['id' => $categoryid]) || $manages($categoryid)) {
                $DB->delete_records('sideline_sourcecat', ['id' => $row->id]);
            }
        }
        $count = $DB->count_records('sideline_sourcecat', ['sidelineid' => $sideline->id]);
        foreach (array_keys($wanted) as $categoryid) {
            if ($count >= self::MAX_SOURCE_CATEGORIES) {
                break;
            }
            $DB->insert_record('sideline_sourcecat', (object) ['sidelineid' => $sideline->id, 'categoryid' => $categoryid,
                'usermodified' => $editorid]);
            $count++;
        }
    }

    /**
     * Course branches of a course union.
     *
     * @param int $sidelineid Instance id.
     * @return int[] Category ids.
     */
    public static function get_source_categories(int $sidelineid): array {
        return array_keys(self::get_source_category_authorities($sidelineid));
    }

    /**
     * Course branches with the teacher whose rights authorise them.
     *
     * @param int $sidelineid Instance id.
     * @return int[] Category id to user id.
     */
    public static function get_source_category_authorities(int $sidelineid): array {
        global $DB;

        if (!get_config('mod_sideline', 'crosscourse')) {
            return [];
        }
        $result = [];
        $rows = $DB->get_records('sideline_sourcecat', ['sidelineid' => $sidelineid], 'id', 'id, categoryid, usermodified');
        foreach ($rows as $row) {
            $result[(int) $row->categoryid] = (int) $row->usermodified;
        }
        return $result;
    }

    /**
     * Replace the admitted groups and groupings of the group mode.
     *
     * @param int $sidelineid Instance id.
     * @param int $courseid Course id; groups of other courses are ignored.
     * @param int[] $groupids Group ids.
     * @param int[] $groupingids Grouping ids.
     * @return bool Whether the release circle grew, so that learners have to decide again.
     */
    public static function save_groups(int $sidelineid, int $courseid, array $groupids, array $groupingids): bool {
        global $DB;

        $DB->delete_records('sideline_group', ['sidelineid' => $sidelineid]);
        foreach (array_unique(array_map('intval', $groupids)) as $id) {
            if ($id > 0 && $DB->record_exists('groups', ['id' => $id, 'courseid' => $courseid])) {
                $DB->insert_record('sideline_group', (object) ['sidelineid' => $sidelineid, 'groupid' => $id, 'groupingid' => 0]);
            }
        }
        foreach (array_unique(array_map('intval', $groupingids)) as $id) {
            if ($id > 0 && $DB->record_exists('groupings', ['id' => $id, 'courseid' => $courseid])) {
                $DB->insert_record('sideline_group', (object) ['sidelineid' => $sidelineid, 'groupid' => 0, 'groupingid' => $id]);
            }
        }
        return self::refresh_group_circle($sidelineid);
    }

    /**
     * Admitted group ids, with groupings expanded. Empty means "all groups of the course".
     *
     * @param int $sidelineid Instance id.
     * @return array [group ids, grouping ids as configured]
     */
    public static function get_groups(int $sidelineid): array {
        global $DB;

        $groups = [];
        $groupings = [];
        foreach ($DB->get_records('sideline_group', ['sidelineid' => $sidelineid]) as $row) {
            if ($row->groupid) {
                $groups[] = (int) $row->groupid;
            } else if ($row->groupingid) {
                $groupings[] = (int) $row->groupingid;
            }
        }
        return [$groups, $groupings];
    }

    /**
     * Admitted group ids with groupings resolved through the core group API.
     *
     * @param int $sidelineid Instance id.
     * @param int $courseid Course id.
     * @return int[]|null Group ids, or null when every group of the course is admitted.
     */
    public static function admitted_group_ids(int $sidelineid, int $courseid): ?array {
        [$groups, $groupings] = self::get_groups($sidelineid);
        if (!$groups && !$groupings) {
            return null;
        }
        foreach ($groupings as $groupingid) {
            foreach (groups_get_all_groups($courseid, 0, $groupingid, 'g.id') as $group) {
                $groups[] = (int) $group->id;
            }
        }
        return array_values(array_unique($groups));
    }
}
