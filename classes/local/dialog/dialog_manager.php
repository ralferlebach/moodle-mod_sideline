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

namespace mod_sideline\local\dialog;

use mod_sideline\local\cache\commentary_cache;

/**
 * Asks, stores and applies interview questions for one learner (P1-08).
 *
 * A question is due when dialogs are enabled, its trigger matches the current commentary (or is
 * "any"), the learner has not interacted with any question within the instance cooldown, and the
 * question itself was not answered or skipped (or its own cooldown has passed). Answers belong to
 * the learner: they can see, change and delete them; teachers cannot read them at all.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dialog_manager {
    /**
     * Questions of an instance, with decoded configuration.
     *
     * @param int $sidelineid Instance id.
     * @param bool $enabledonly Only enabled questions.
     * @return \stdClass[]
     */
    public static function questions(int $sidelineid, bool $enabledonly = true): array {
        global $DB;

        $params = ['sidelineid' => $sidelineid];
        if ($enabledonly) {
            $params['enabled'] = 1;
        }
        $questions = $DB->get_records('sideline_question', $params, 'sortorder, id');
        foreach ($questions as $q) {
            [$q->configdata] = question_type::normalise_config($q->qtype, (array) json_decode((string) $q->config, true));
        }
        return $questions;
    }

    /**
     * The question to show now, if any.
     *
     * @param \stdClass $sideline Instance record.
     * @param int $userid User id.
     * @param string[] $currentkeys Rule keys of the current commentary.
     * @param int $now Current time.
     * @return \stdClass|null Question with configdata.
     */
    public static function due_question(\stdClass $sideline, int $userid, array $currentkeys, int $now): ?\stdClass {
        global $DB;

        if (empty($sideline->dialogenabled)) {
            return null;
        }
        $answers = $DB->get_records(
            'sideline_answer',
            ['sidelineid' => $sideline->id, 'userid' => $userid],
            '',
            'questionid, timemodified',
        );
        $lastinteraction = $answers ? max(array_map(static fn($a) => (int) $a->timemodified, $answers)) : 0;
        if ($lastinteraction && $now - $lastinteraction < (int) $sideline->dialogcooldown) {
            return null;
        }
        foreach (self::questions((int) $sideline->id) as $question) {
            if ($question->triggerkey !== 'any' && !in_array($question->triggerkey, $currentkeys, true)) {
                continue;
            }
            $answer = $answers[$question->id] ?? null;
            if ($answer && (!(int) $question->cooldown || $now - (int) $answer->timemodified < (int) $question->cooldown)) {
                continue;
            }
            return $question;
        }
        return null;
    }

    /**
     * Store an answer or a skip of the learner.
     *
     * @param \stdClass $sideline Instance record.
     * @param int $userid User id.
     * @param int $questionid Question id; must belong to the instance.
     * @param mixed $value Raw value, ignored when skipping.
     * @param bool $skip Whether the learner skipped.
     * @param int $now Current time.
     */
    public static function answer(\stdClass $sideline, int $userid, int $questionid, mixed $value, bool $skip, int $now): void {
        global $DB;

        $question = $DB->get_record('sideline_question', ['id' => $questionid, 'sidelineid' => $sideline->id, 'enabled' => 1]);
        if (!$question) {
            throw new \invalid_parameter_exception('Unknown question');
        }
        $stored = null;
        if (!$skip) {
            [$config] = question_type::normalise_config($question->qtype, (array) json_decode((string) $question->config, true));
            $stored = question_type::normalise_value($question->qtype, $config, $value);
            if ($stored === null) {
                throw new \invalid_parameter_exception('Invalid answer');
            }
        }
        $row = $DB->get_record('sideline_answer', ['questionid' => $questionid, 'userid' => $userid]);
        $record = (object) [
            'questionid' => $questionid,
            'sidelineid' => $sideline->id,
            'userid' => $userid,
            'value' => $stored,
            'skipped' => $skip ? 1 : 0,
            'timemodified' => $now,
        ];
        if ($row) {
            $record->id = $row->id;
            $DB->update_record('sideline_answer', $record);
        } else {
            $record->timecreated = $now;
            $DB->insert_record('sideline_answer', $record);
        }
        commentary_cache::invalidate_user((int) $sideline->id, $userid);
    }

    /**
     * Delete one of the learner's answers.
     *
     * @param \stdClass $sideline Instance record.
     * @param int $userid User id.
     * @param int $questionid Question id.
     */
    public static function delete_answer(\stdClass $sideline, int $userid, int $questionid): void {
        global $DB;

        $DB->delete_records('sideline_answer', ['sidelineid' => $sideline->id, 'userid' => $userid, 'questionid' => $questionid]);
        commentary_cache::invalidate_user((int) $sideline->id, $userid);
    }

    /**
     * The effects of the learner's current answers.
     *
     * @param \stdClass $sideline Instance record.
     * @param int $userid User id.
     * @return string[] Unique effects other than none.
     */
    public static function effects(\stdClass $sideline, int $userid): array {
        $effects = [];
        foreach (self::my_answers($sideline, $userid) as $answer) {
            if ($answer['effect'] !== 'none') {
                $effects[] = $answer['effect'];
            }
        }
        $effects = array_values(array_unique($effects));
        sort($effects);
        return $effects;
    }

    /**
     * The learner's own answers, for display to the learner only.
     *
     * @param \stdClass $sideline Instance record.
     * @param int $userid User id.
     * @return array[] questionid, prompt, qtype, display, skipped, effect, timemodified.
     */
    public static function my_answers(\stdClass $sideline, int $userid): array {
        global $DB;

        if (empty($sideline->dialogenabled)) {
            return [];
        }
        $questions = self::questions((int) $sideline->id, false);
        $result = [];
        foreach ($DB->get_records('sideline_answer', ['sidelineid' => $sideline->id, 'userid' => $userid], 'timemodified') as $a) {
            $q = $questions[$a->questionid] ?? null;
            if (!$q) {
                continue;
            }
            $effect = (!$a->skipped && $a->value !== null && $q->enabled)
                ? question_type::effect($q->qtype, $q->configdata, $a->value) : 'none';
            $result[] = [
                'questionid' => (int) $q->id,
                'prompt' => (string) $q->prompt,
                'qtype' => (string) $q->qtype,
                'display' => $a->skipped ? '' : self::display_value($q, (string) $a->value),
                'skipped' => (bool) $a->skipped,
                'effect' => $effect,
                'timemodified' => (int) $a->timemodified,
            ];
        }
        return $result;
    }

    /**
     * A readable form of a stored value, for the learner.
     *
     * @param \stdClass $question Question with configdata.
     * @param string $stored JSON value.
     * @return string
     */
    private static function display_value(\stdClass $question, string $stored): string {
        $value = json_decode($stored, true);
        return match ($question->qtype) {
            question_type::MC => (string) ($question->configdata['options'][(int) $value]['label'] ?? ''),
            question_type::SEMDIFF => is_array($value) ? implode(', ', $value) : '',
            default => (string) $value,
        };
    }

    /**
     * Save a question from the teacher's form.
     *
     * @param int $sidelineid Instance id.
     * @param \stdClass $data qtype, prompt, config (array), triggerkey, cooldown, enabled, optional id.
     * @return int Question id.
     */
    public static function save_question(int $sidelineid, \stdClass $data): int {
        global $DB;

        $type = (string) $data->qtype;
        [$config, $errors] = question_type::normalise_config($type, (array) $data->config);
        $prompt = trim(clean_param((string) $data->prompt, PARAM_NOTAGS));
        if ($prompt === '') {
            $errors[] = get_string('required');
        }
        if ($errors) {
            throw new \invalid_parameter_exception(implode(' ', $errors));
        }
        $record = (object) [
            'sidelineid' => $sidelineid,
            'qtype' => $type,
            'prompt' => $prompt,
            'config' => json_encode($config, JSON_UNESCAPED_UNICODE),
            'triggerkey' => preg_match('/^[a-z0-9_]{1,60}$/', (string) ($data->triggerkey ?? 'any')) ? $data->triggerkey : 'any',
            'cooldown' => max(0, (int) ($data->cooldown ?? 0)),
            'enabled' => empty($data->enabled) ? 0 : 1,
            'timemodified' => time(),
        ];
        if (!empty($data->id)) {
            $old = $DB->get_record('sideline_question', ['id' => $data->id, 'sidelineid' => $sidelineid], '*', MUST_EXIST);
            $record->id = (int) $data->id;
            $DB->update_record('sideline_question', $record);
            // Old answers would be read with a different meaning (a text as an option index, an index
            // pointing at another option): they are deleted, never reinterpreted.
            if ($old->qtype !== $record->qtype || $old->config !== $record->config) {
                $DB->delete_records('sideline_answer', ['questionid' => $record->id]);
                commentary_cache::invalidate_instance($sidelineid);
            }
            return $record->id;
        }
        $record->sortorder = (int) $DB->get_field_sql(
            'SELECT COALESCE(MAX(sortorder), 0) + 1 FROM {sideline_question} WHERE sidelineid = ?',
            [$sidelineid]
        );
        $record->timecreated = $record->timemodified;
        return (int) $DB->insert_record('sideline_question', $record);
    }

    /**
     * Delete a question and every answer to it.
     *
     * @param int $sidelineid Instance id.
     * @param int $questionid Question id.
     */
    public static function delete_question(int $sidelineid, int $questionid): void {
        global $DB;

        $DB->get_record('sideline_question', ['id' => $questionid, 'sidelineid' => $sidelineid], 'id', MUST_EXIST);
        $DB->delete_records('sideline_answer', ['questionid' => $questionid]);
        $DB->delete_records('sideline_question', ['id' => $questionid]);
        commentary_cache::invalidate_instance($sidelineid);
    }

    /**
     * The question as plain data for the panel.
     *
     * @param \stdClass $question Question with configdata.
     * @return array
     */
    public static function export_for_panel(\stdClass $question): array {
        $config = $question->configdata;
        return [
            'id' => (int) $question->id,
            'qtype' => (string) $question->qtype,
            'prompt' => format_string($question->prompt),
            'options' => array_map(static fn($o) => (string) $o['label'], $config['options'] ?? []),
            'min' => (int) ($config['min'] ?? 0),
            'max' => (int) ($config['max'] ?? 0),
            'step' => (int) ($config['step'] ?? 1),
            'minlabel' => (string) ($config['minlabel'] ?? ''),
            'maxlabel' => (string) ($config['maxlabel'] ?? ''),
            'pairs' => array_map(static fn($p) => ['left' => $p[0], 'right' => $p[1]], $config['pairs'] ?? []),
            'scale' => (int) ($config['scale'] ?? 7),
            'maxlength' => (int) ($config['maxlength'] ?? 0),
        ];
    }
}
