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

namespace mod_sideline\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_sideline\local\ai\persona_registry;
use mod_sideline\local\group\sharing_manager;
use mod_sideline\local\instance_config;
use mod_sideline\local\persona\persona_resolver;
use mod_sideline\local\service\commentary_service;

/**
 * Webservice get_commentary: the commentary of the calling user for one Sideline activity.
 *
 * The service always answers for the calling user. There is no userid parameter, so there is no
 * way to ask for somebody else, which is how P-40 (no teacher view) is kept at the API level.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_commentary extends external_api {
    use instance_access;

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id of the Sideline activity'),
        ]);
    }

    /**
     * Get the commentary.
     *
     * @param int $cmid Course module id.
     * @return array
     */
    public static function execute(int $cmid): array {
        global $USER;

        ['cmid' => $cmid] = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);
        [$course, , $context, $sideline] = self::access($cmid);

        // The user of the request, never a client-supplied one.
        $result = (new commentary_service())->get($sideline, $course, $USER, $context, time());

        $commentary = $result['commentary'] ?? [];
        $blocks = [];
        foreach ($commentary['blocks'] ?? [] as $block) {
            $item = ['key' => $block['key'], 'text' => $block['text'], 'actionlabel' => '', 'actionurl' => ''];
            if (!empty($block['action'])) {
                $item['actionlabel'] = $block['action']['label'];
                $item['actionurl'] = $block['action']['url'];
            }
            $blocks[] = $item;
        }

        $personas = [];
        if (!empty($sideline->personachoice) && $result['status'] === commentary_service::STATUS_OK) {
            $names = persona_registry::options();
            foreach (persona_resolver::offered($sideline) as $key) {
                $personas[] = ['key' => $key, 'name' => $names[$key] ?? $key];
            }
        }

        $sharingmode = (string) $sideline->sharingmode;
        $sharing = sharing_manager::status($sideline, (int) $USER->id);

        return [
            'status' => $result['status'],
            'consent' => $result['consent'],
            'source' => $commentary['source'] ?? '',
            'persona' => $result['persona'] ?? '',
            'blocks' => $blocks,
            'question' => $result['question'] ? [$result['question']] : [],
            'personas' => $personas,
            'dialogenabled' => !empty($sideline->dialogenabled),
            'sharingmode' => $sharingmode,
            'grouplevel' => $sharingmode === instance_config::GROUP_INDIVIDUAL ? '' : (string) $sideline->grouppublish,
            'sharepublish' => $sharing['publish'],
            'sharenamed' => $sharing['named'],
            'sharereconsent' => $sharing['reconsent'],
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHAEXT, 'ok or consent_required'),
            'consent' => new external_value(PARAM_ALPHAEXT, 'Consent status of the calling user'),
            'source' => new external_value(PARAM_ALPHA, 'ai, template or generic, empty when none'),
            'persona' => new external_value(PARAM_ALPHANUMEXT, 'Persona key'),
            'blocks' => new external_multiple_structure(
                new external_single_structure([
                    'key' => new external_value(PARAM_ALPHANUMEXT, 'Rule key'),
                    'text' => new external_value(PARAM_RAW, 'Plain text, rendered as text only'),
                    'actionlabel' => new external_value(PARAM_TEXT, 'Label of the action link, empty when none'),
                    'actionurl' => new external_value(PARAM_URL, 'Server-resolved URL, empty when none'),
                ])
            ),
            'question' => new external_multiple_structure(self::question_structure(), 'Zero or one due question'),
            'personas' => new external_multiple_structure(new external_single_structure([
                'key' => new external_value(PARAM_ALPHANUMEXT, 'Persona key'),
                'name' => new external_value(PARAM_TEXT, 'Persona name'),
            ]), 'Personas the learner may choose from, empty when there is no choice'),
            'dialogenabled' => new external_value(PARAM_BOOL, 'Whether interview questions are enabled'),
            'sharingmode' => new external_value(PARAM_ALPHA, 'individual, closed or open'),
            'grouplevel' => new external_value(PARAM_ALPHAEXT, 'Highest publication level, empty in individual mode'),
            'sharepublish' => new external_value(PARAM_BOOL, 'The calling user shares completions'),
            'sharenamed' => new external_value(PARAM_BOOL, 'The calling user allows name display'),
            'sharereconsent' => new external_value(PARAM_BOOL, 'The sharing information changed since the last decision'),
        ]);
    }

    /**
     * Structure of a question for the panel.
     *
     * @return external_single_structure
     */
    public static function question_structure(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Question id'),
            'qtype' => new external_value(PARAM_ALPHA, 'mc, text, slider or semdiff'),
            'prompt' => new external_value(PARAM_TEXT, 'Question text'),
            'options' => new external_multiple_structure(new external_value(PARAM_TEXT, 'Option label')),
            'min' => new external_value(PARAM_INT, 'Slider minimum'),
            'max' => new external_value(PARAM_INT, 'Slider maximum'),
            'step' => new external_value(PARAM_INT, 'Slider step'),
            'minlabel' => new external_value(PARAM_TEXT, 'Slider label at the minimum'),
            'maxlabel' => new external_value(PARAM_TEXT, 'Slider label at the maximum'),
            'pairs' => new external_multiple_structure(new external_single_structure([
                'left' => new external_value(PARAM_TEXT, 'Left adjective'),
                'right' => new external_value(PARAM_TEXT, 'Right adjective'),
            ])),
            'scale' => new external_value(PARAM_INT, 'Points of the semantic differential'),
            'maxlength' => new external_value(PARAM_INT, 'Longest free text'),
        ]);
    }
}
