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

namespace mod_sideline\output;

/**
 * The mount point of the React panel, shared by the view page and the inline display.
 *
 * The panel receives no Moodle objects and no learner data, only the course module id, the
 * animation switch and the localised UI strings (P2-14: no hard-coded strings in the UI).
 *
 * Moodle 5.3 and later render the React panel (js/esm). Moodle 4.5 to 5.2 have no complete React
 * pipeline (5.2 lacks the ESM ajax module), so they get the equivalent AMD panel (amd/src/panel.js)
 * with the same behaviour, markup roles and web services.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class panel {
    /** @var string[] UI string keys, passed to the panel. */
    public const STRINGS = [
        'region', 'loading', 'error', 'retry', 'consentTitle', 'consentText', 'consentAccept', 'consentDecline',
        'consentWithdraw', 'consentDeclined', 'questionSubmit', 'questionSkip', 'questionTitle', 'answersShow',
        'answersHide', 'answersEmpty', 'answerDelete', 'answerEffect', 'personaLabel', 'personaDefault',
        'sharingTitle', 'sharingText', 'sharingPublish', 'sharingNamed', 'sharingReconsent', 'sharingSave',
        'sliderValue', 'textRemaining',
    ];

    /**
     * HTML of the mount point.
     *
     * @param int $cmid Course module id.
     * @param bool $typing Typing animation.
     * @param bool $inline Whether shown on the course page.
     * @return string
     */
    public static function render(int $cmid, bool $typing, bool $inline): string {
        global $PAGE;

        $props = ['cmid' => $cmid, 'typing' => $typing, 'inline' => $inline, 'strings' => self::strings()];
        if (self::uses_react()) {
            return \html_writer::react_component('@moodle/lms/mod_sideline/CommentaryPanel', $props);
        }
        $id = \html_writer::random_id('mod_sideline_panel_');
        $PAGE->requires->js_call_amd('mod_sideline/panel', 'init', [$id]);
        return \html_writer::div('', 'mod_sideline-amdpanel', [
            'id' => $id,
            'data-props' => json_encode($props, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * Whether this Moodle renders React components (5.3 and later).
     *
     * @return bool
     */
    public static function uses_react(): bool {
        return method_exists(\html_writer::class, 'react_component');
    }

    /**
     * Localised UI strings.
     *
     * @return string[]
     */
    public static function strings(): array {
        $strings = [];
        foreach (self::STRINGS as $key) {
            $strings[$key] = get_string('ui_' . strtolower($key), 'mod_sideline');
        }
        return $strings;
    }
}
