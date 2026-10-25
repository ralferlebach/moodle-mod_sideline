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

namespace mod_sideline\local\service;

use mod_sideline\local\instance_config;

/**
 * Resolves which activities a Sideline instance observes for one user (L-02, P1-05).
 *
 * Only activities the user can see, or sees as locked, are returned. Hidden and stealth
 * activities never are, whatever the user's access path. Section scope descends into nested
 * subsections (delegated sections). A course union adds further courses, each of them only when
 * the user can currently access it; this is checked on every request, so a revoked enrolment
 * takes effect at once.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scope_resolver {
    /** @var int Depth limit for nested subsections, protects against cycles in broken data. */
    private const MAX_DEPTH = 10;

    /**
     * Resolve the observed activities.
     *
     * @param \stdClass $sideline Instance record.
     * @param int $userid User the commentary is for.
     * @return \cm_info[] Keyed by course module id, in course order.
     */
    public function resolve(\stdClass $sideline, int $userid): array {
        $modinfo = get_fast_modinfo((int) $sideline->course, $userid);

        $candidates = [];
        if ($sideline->scopetype === instance_config::SCOPE_SECTION) {
            $section = $modinfo->get_section_info((int) $sideline->scopesection);
            if ($section) {
                $candidates = $this->section_cms($section, !empty($sideline->scopesubsections), 0);
            }
        } else if ($sideline->scopetype === instance_config::SCOPE_ACTIVITIES) {
            $selected = array_flip(instance_config::get_scope_activities((int) $sideline->id));
            foreach ($modinfo->get_cms() as $cm) {
                if (isset($selected[$cm->id])) {
                    $candidates[] = $cm;
                }
            }
        } else {
            $candidates = array_values($modinfo->get_cms());
        }

        $result = [];
        foreach ($candidates as $cm) {
            if ($this->observable($cm)) {
                $result[$cm->id] = $cm;
            }
        }

        foreach (instance_config::get_sources((int) $sideline->id) as $courseid) {
            foreach ($this->source_cms($courseid, $userid) as $cm) {
                $result[$cm->id] = $cm;
            }
        }
        return $result;
    }

    /**
     * Course modules of a section, descending into delegated subsections.
     *
     * @param \section_info $section Section.
     * @param bool $recursive Whether to descend.
     * @param int $depth Current depth.
     * @return \cm_info[]
     */
    private function section_cms(\section_info $section, bool $recursive, int $depth): array {
        $result = [];
        foreach ($section->get_sequence_cm_infos() as $cm) {
            $delegated = $cm->get_delegated_section_info();
            if ($delegated) {
                // A subsection is a container: only its content is observed, and only if visible.
                if ($recursive && $depth < self::MAX_DEPTH && $cm->uservisible && $delegated->uservisible) {
                    $result = array_merge($result, $this->section_cms($delegated, true, $depth + 1));
                }
                continue;
            }
            $result[] = $cm;
        }
        return $result;
    }

    /**
     * All observable activities of a further course, if the user may access it right now.
     *
     * @param int $courseid Course id.
     * @param int $userid User id.
     * @return \cm_info[]
     */
    private function source_cms(int $courseid, int $userid): array {
        global $DB;

        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course) {
            return [];
        }
        $context = \context_course::instance($courseid);
        // Active enrolment and course visibility, per request (P1-05: rights per source course).
        if (!is_enrolled($context, $userid, '', true) || !can_access_course($course, $userid, '', true)) {
            return [];
        }
        $result = [];
        foreach (get_fast_modinfo($course, $userid)->get_cms() as $cm) {
            if ($this->observable($cm)) {
                $result[] = $cm;
            }
        }
        return $result;
    }

    /**
     * Whether a course module may be observed and mentioned for this user.
     *
     * @param \cm_info $cm Course module.
     * @return bool
     */
    private function observable(\cm_info $cm): bool {
        if ($cm->modname === 'sideline' || $cm->deletioninprogress || $cm->get_delegated_section_info()) {
            return false;
        }
        // Hidden or stealth activities never appear (P0-01), including activities that are only
        // reachable by link because their section is hidden.
        if (!$cm->visible || $cm->is_stealth()) {
            return false;
        }
        // The section must be shown to the user: a section restricted with "hide entirely" hides
        // everything in it, even an activity that carries its own visible restriction.
        $section = $cm->get_section_info();
        if (!$section || !$section->visible || (!$section->uservisible && empty($section->availableinfo))) {
            return false;
        }
        // Visible, or visible as a locked item with an explanation. Everything else stays invisible.
        return $cm->uservisible || !empty($cm->availableinfo);
    }
}
