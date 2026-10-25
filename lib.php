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

/**
 * Library of interface functions and constants for mod_sideline.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Declare which optional Moodle features the module supports.
 *
 * @param string $feature FEATURE_xx constant.
 * @return mixed True if supported, null if unknown, false if not supported.
 */
function sideline_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
        case FEATURE_BACKUP_MOODLE2:
        case FEATURE_SHOW_DESCRIPTION:
        case FEATURE_CAN_DISPLAY:
            return true;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_COMMUNICATION;
        case FEATURE_GRADE_HAS_GRADE:
        case FEATURE_GROUPS:
        case FEATURE_GROUPINGS:
        case FEATURE_COMPLETION_TRACKS_VIEWS:
        case FEATURE_COMPLETION_HAS_RULES:
        case FEATURE_ADVANCED_GRADING:
        case FEATURE_CONTROLS_GRADE_VISIBILITY:
            // Sideline never grades, never completes anything and never unlocks anything.
            return false;
        default:
            return null;
    }
}

/**
 * Add a new instance.
 *
 * @param stdClass $data Data from mod_form.
 * @param mod_sideline_mod_form|null $mform The form.
 * @return int The new instance id.
 */
function sideline_add_instance($data, $mform = null) {
    global $DB;

    $data->timecreated = time();
    $data->timemodified = $data->timecreated;
    $data->configversion = 1;
    $data->consentversion = 1;
    $data = \mod_sideline\local\instance_config::normalise($data);

    $data->groupversion = 1;

    $data->id = $DB->insert_record('sideline', $data);
    sideline_save_relations($data);

    return $data->id;
}

/**
 * Update an existing instance.
 *
 * Every change bumps the configuration version, which is part of the cache fingerprint. All cached
 * commentary of the instance becomes stale at once.
 *
 * @param stdClass $data Data from mod_form.
 * @param mod_sideline_mod_form|null $mform The form.
 * @return bool
 */
function sideline_update_instance($data, $mform = null) {
    global $DB;

    $old = $DB->get_record('sideline', ['id' => $data->instance], '*', MUST_EXIST);

    $data->id = $data->instance;
    $data->timemodified = time();
    $data->configversion = (int) $old->configversion + 1;
    $data = \mod_sideline\local\instance_config::normalise($data);
    // Wider consent or sharing than learners agreed to needs new decisions; narrowing applies at once.
    \mod_sideline\local\instance_config::apply_version_bumps($old, $data);

    $DB->update_record('sideline', $data);
    sideline_save_relations($data);
    \mod_sideline\local\cache\commentary_cache::invalidate_instance($data->id);

    return true;
}

/**
 * Delete an instance and everything that belongs to it.
 *
 * @param int $id Instance id.
 * @return bool
 */
function sideline_delete_instance($id) {
    global $DB;

    if (!$DB->record_exists('sideline', ['id' => $id])) {
        return false;
    }
    foreach (
        ['sideline_cache', 'sideline_consent', 'sideline_consent_log', 'sideline_scope', 'sideline_source',
        'sideline_group', 'sideline_pref', 'sideline_answer', 'sideline_question', 'sideline_rule',
        'sideline_rulehit', 'sideline_publish', 'sideline_trace'] as $table
    ) {
        $DB->delete_records($table, ['sidelineid' => $id]);
    }
    $DB->delete_records('sideline', ['id' => $id]);

    return true;
}

/**
 * Mark the activity as viewed: log only. The plugin has no completion by view.
 *
 * @param stdClass $sideline Instance record.
 * @param stdClass $course Course record.
 * @param cm_info|stdClass $cm Course module.
 * @param context_module $context Module context.
 */
function sideline_view($sideline, $course, $cm, $context) {
    $event = \mod_sideline\event\course_module_viewed::create([
        'objectid' => $sideline->id,
        'context' => $context,
    ]);
    $event->add_record_snapshot('course_modules', $cm);
    $event->add_record_snapshot('course', $course);
    $event->add_record_snapshot('sideline', $sideline);
    $event->trigger();
}

/**
 * Save the relations of an instance: activity scope, course union, admitted groups.
 *
 * @param stdClass $data Instance data with id and course.
 */
function sideline_save_relations(stdClass $data): void {
    global $USER;

    \mod_sideline\local\instance_config::save_scope_activities($data->id, $data->scopecmids ?? []);
    if (isset($data->sourcecourses)) {
        \mod_sideline\local\instance_config::save_sources($data, (array) $data->sourcecourses, (int) $USER->id);
    }
    if (isset($data->groupids) || isset($data->groupingids)) {
        \mod_sideline\local\instance_config::save_groups(
            (int) $data->id,
            (int) $data->course,
            (array) ($data->groupids ?? []),
            (array) ($data->groupingids ?? [])
        );
    }
}

/**
 * Cached course module information: the display mode, so the course page needs no extra query.
 *
 * @param stdClass $coursemodule Course module record.
 * @return cached_cm_info|false
 */
function sideline_get_coursemodule_info($coursemodule) {
    global $DB;

    $sideline = $DB->get_record('sideline', ['id' => $coursemodule->instance], 'id, name, intro, introformat, display, typing');
    if (!$sideline) {
        return false;
    }
    $info = new cached_cm_info();
    $info->name = $sideline->name;
    if ($coursemodule->showdescription) {
        $info->content = format_module_intro('sideline', $sideline, $coursemodule->id, false);
    }
    $info->customdata = ['display' => $sideline->display, 'typing' => (int) $sideline->typing];
    return $info;
}

/**
 * Inline instances behave like a text and media area: no link to a separate page.
 *
 * @param cm_info $cm Course module.
 */
function sideline_cm_info_dynamic(cm_info $cm) {
    if (($cm->customdata['display'] ?? 'page') === \mod_sideline\local\instance_config::DISPLAY_INLINE) {
        $cm->set_no_view_link();
    }
}

/**
 * Inline display on the course page (P1-11). The panel loads its content itself, only for the
 * logged-in user, after the same checks as the view page; nothing personal is in the course page HTML.
 *
 * @param cm_info $cm Course module.
 */
function sideline_cm_info_view(cm_info $cm) {
    if (($cm->customdata['display'] ?? 'page') !== \mod_sideline\local\instance_config::DISPLAY_INLINE) {
        return;
    }
    if (!$cm->uservisible || !isloggedin() || isguestuser()) {
        return;
    }
    if (!has_capability('mod/sideline:view', $cm->context)) {
        return;
    }
    $content = (string) $cm->content;
    $content .= \mod_sideline\output\panel::render((int) $cm->id, !empty($cm->customdata['typing']), true);
    $cm->set_content($content, true);
}

/**
 * Tracing was switched on or its duration changed: set the end time.
 *
 * @param string $name Setting name.
 */
function mod_sideline_tracing_updated(string $name): void {
    if (get_config('mod_sideline', 'tracing')) {
        $duration = (int) get_config('mod_sideline', 'tracingduration') ?: DAYSECS;
        set_config('tracinguntil', time() + $duration, 'mod_sideline');
    } else {
        set_config('tracinguntil', 0, 'mod_sideline');
    }
}

/**
 * Links to the teacher pages in the activity's settings navigation.
 *
 * @param settings_navigation $settings Settings navigation.
 * @param navigation_node $node Activity node.
 */
function sideline_extend_settings_navigation(settings_navigation $settings, navigation_node $node) {
    $cm = $settings->get_page()->cm;
    if (!$cm || !has_capability('mod/sideline:manage', $cm->context)) {
        return;
    }
    foreach (['questions' => 'questions_manage', 'rules' => 'rules_manage', 'transfer' => 'transfer'] as $page => $string) {
        $node->add(
            get_string($string, 'mod_sideline'),
            new moodle_url('/mod/sideline/' . $page . '.php', ['id' => $cm->id]),
            navigation_node::TYPE_SETTING,
            null,
            'mod_sideline_' . $page
        );
    }
}
