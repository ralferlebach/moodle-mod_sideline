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

namespace mod_sideline\local\persona;

use mod_sideline\local\ai\persona_registry;
use mod_sideline\local\cache\commentary_cache;

/**
 * Administration of site personas: versions, approval and revocation (P1-07).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class persona_manager {
    /** @var string Status: being written, not usable. */
    public const DRAFT = 'draft';
    /** @var string Status: approved and usable. */
    public const APPROVED = 'approved';
    /** @var string Status: approval revoked, not usable. */
    public const REVOKED = 'revoked';

    /** @var int Longest style instruction. */
    public const MAX_STYLE = 1500;

    /**
     * Validation errors of persona data.
     *
     * @param \stdClass $data shortname, name, style, opener.
     * @param int $id Id of the persona being edited, 0 for a new one.
     * @return string[] Field to error message.
     */
    public static function validate(\stdClass $data, int $id = 0): array {
        global $DB;

        $errors = [];
        $shortname = (string) ($data->shortname ?? '');
        if (!preg_match('/^[a-z][a-z0-9_]{1,30}$/', $shortname)) {
            $errors['shortname'] = get_string('err_personashortname', 'mod_sideline');
        } else if ($DB->record_exists_select('sideline_persona', 'shortname = ? AND id <> ?', [$shortname, $id])) {
            $errors['shortname'] = get_string('err_personaexists', 'mod_sideline');
        }
        if (trim((string) ($data->name ?? '')) === '') {
            $errors['name'] = get_string('required');
        }
        $style = (string) ($data->style ?? '');
        if (trim($style) === '' || mb_strlen($style) > self::MAX_STYLE) {
            $errors['style'] = get_string('err_personastyle', 'mod_sideline', self::MAX_STYLE);
        } else if (preg_match('/[<>]|https?:|www\./i', $style)) {
            $errors['style'] = get_string('err_personamarkup', 'mod_sideline');
        }
        if (preg_match('/[<>]|https?:|www\./i', (string) ($data->opener ?? ''))) {
            $errors['opener'] = get_string('err_personamarkup', 'mod_sideline');
        }
        return $errors;
    }

    /**
     * Create or update a persona. Every save is a new version and a draft that needs approval.
     *
     * @param \stdClass $data shortname, name, style, opener, optional id.
     * @param int $userid Acting administrator.
     * @return int Persona id.
     */
    public static function save(\stdClass $data, int $userid): int {
        global $DB;

        $id = (int) ($data->id ?? 0);
        if ($errors = self::validate($data, $id)) {
            throw new \invalid_parameter_exception(implode(' ', $errors));
        }
        $now = time();
        $record = (object) [
            'shortname' => $data->shortname,
            'name' => trim($data->name),
            'style' => trim(clean_param($data->style, PARAM_NOTAGS)),
            'opener' => trim(clean_param((string) ($data->opener ?? ''), PARAM_NOTAGS)),
            'status' => self::DRAFT,
            'usermodified' => $userid,
            'approvedby' => 0,
            'timemodified' => $now,
        ];
        if ($id) {
            $old = $DB->get_record('sideline_persona', ['id' => $id], '*', MUST_EXIST);
            $record->id = $id;
            $record->version = (int) $old->version + 1;
            $DB->update_record('sideline_persona', $record);
        } else {
            $record->version = 1;
            $record->timecreated = $now;
            $id = $DB->insert_record('sideline_persona', $record);
        }
        self::changed();
        return $id;
    }

    /**
     * Change the approval status.
     *
     * @param int $id Persona id.
     * @param string $status APPROVED or REVOKED.
     * @param int $userid Acting administrator.
     */
    public static function set_status(int $id, string $status, int $userid): void {
        global $DB;

        if (!in_array($status, [self::APPROVED, self::REVOKED], true)) {
            throw new \coding_exception('Invalid persona status');
        }
        $DB->get_record('sideline_persona', ['id' => $id], 'id', MUST_EXIST);
        $DB->update_record('sideline_persona', (object) [
            'id' => $id,
            'status' => $status,
            'approvedby' => $status === self::APPROVED ? $userid : 0,
            'timemodified' => time(),
        ]);
        self::changed();
    }

    /**
     * After any change: forget cached definitions. Cached texts become stale through the fingerprint.
     */
    private static function changed(): void {
        persona_registry::reset();
        // Not strictly needed (the fingerprint carries key, status and version), but it frees the space at once.
        commentary_cache::mark_all_dirty();
    }
}
