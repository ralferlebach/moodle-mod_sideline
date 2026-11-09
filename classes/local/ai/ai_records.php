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

namespace mod_sideline\local\ai;

/**
 * Which entries of Moodle's AI subsystem an instance made for a learner, and emptying exactly these.
 *
 * The plugin registers its AI calls in the learner's user context (core_text_generator), so that teachers
 * do not see in the AI usage report who uses the coach. Other plugins and other instances may register calls
 * in the same context, and the subsystem's privacy API can only empty all of them. So every call of the plugin
 * records the id of the entry it caused (table sideline_aicall), and a withdrawal empties only those.
 *
 * Attribution: while the call runs, the person's AI lock of this plugin is held, so no other instance makes a
 * call for the same person at the same time. The entry is the one created after the call started, for this
 * person and context, with exactly the prompt that was sent.
 *
 * Emptying: the same fields that the subsystem's own privacy provider empties
 * (core_ai\privacy\provider::delete_data_for_user(): prompt, responseid, fingerprint, generatedcontent of
 * ai_action_generate_text), limited to the recorded entries. The subsystem has no API to empty a single entry.
 * The register row itself, with time, provider and token numbers, stays, as it does after the subsystem's own
 * deletion. The person's acceptance of the AI policy is not touched.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_records {
    /** @var string[] Fields that hold text of the person, as emptied by the subsystem's privacy provider. */
    public const TEXT_FIELDS = ['prompt', 'responseid', 'fingerprint', 'generatedcontent'];

    /** @var int Seconds to wait for a running AI call of the same person in another instance. */
    public const LOCK_TIMEOUT = 20;

    /** @var int Ids per query. */
    private const CHUNK = 500;

    /**
     * Whether a prompt has the form of this plugin's prompts (prompt_builder, unchanged since 0.3.0): the generation
     * with its facts or the translation with its texts, and the fixed output instruction.
     *
     * @param string $prompt Prompt as the AI subsystem stored it.
     * @return bool
     */
    public static function is_plugin_prompt(string $prompt): bool {
        return str_contains($prompt, 'OUTPUT FORMAT: respond with JSON only')
            && (str_contains($prompt, "\n\nFACTS:\n") || str_contains($prompt, "\n\nTEXTS:\n"));
    }

    /**
     * The AI lock of a person: one AI call of the plugin per person at a time.
     *
     * @param int $userid User id.
     * @return \core\lock\lock|false
     */
    public static function lock(int $userid) {
        return \core\lock\lock_config::get_lock_factory('mod_sideline')->get_lock('ai_' . $userid, self::LOCK_TIMEOUT);
    }

    /**
     * The highest id of the subsystem's register before a call.
     *
     * @return int
     */
    public static function last_id(): int {
        global $DB;

        return (int) $DB->get_field_sql('SELECT MAX(id) FROM {ai_action_register}');
    }

    /**
     * Record the entries a call caused: after $after, for this person and context, with this prompt.
     *
     * @param int $sidelineid Instance id.
     * @param int $userid User id.
     * @param int $contextid Context the call was registered in.
     * @param int $after Highest register id before the call.
     * @param string $prompt Prompt that was sent.
     * @param int $now Current time.
     * @return int Number of recorded entries.
     */
    public static function attribute(int $sidelineid, int $userid, int $contextid, int $after, string $prompt, int $now): int {
        global $DB;

        $rows = $DB->get_records_sql(
            "SELECT r.id, t.prompt
               FROM {ai_action_register} r
               JOIN {ai_action_generate_text} t ON t.id = r.actionid
              WHERE r.actionname = :action AND r.userid = :userid AND r.contextid = :contextid AND r.id > :after",
            ['action' => 'generate_text', 'userid' => $userid, 'contextid' => $contextid, 'after' => $after]
        );
        $count = 0;
        foreach ($rows as $row) {
            if ((string) $row->prompt !== $prompt || $DB->record_exists('sideline_aicall', ['actionid' => $row->id])) {
                continue;
            }
            $DB->insert_record('sideline_aicall', (object) ['sidelineid' => $sidelineid, 'userid' => $userid,
                'actionid' => $row->id, 'timecreated' => $now], false);
            $count++;
        }
        if (!$count) {
            debugging('mod_sideline: the entry of an AI call was not found; it cannot be emptied on withdrawal.', DEBUG_DEVELOPER);
        }
        return $count;
    }

    /**
     * Empty what the subsystem kept of an instance's calls, for some people or for everybody.
     *
     * Idempotent: the records of origin are deleted with it, a second call finds nothing.
     *
     * @param int $sidelineid Instance id.
     * @param int[]|null $userids Users, or null for every user of the instance.
     */
    public static function forget(int $sidelineid, ?array $userids = null): void {
        global $DB;

        $select = 'sidelineid = :sid';
        $params = ['sid' => $sidelineid];
        if ($userids !== null) {
            $userids = array_values(array_unique(array_map('intval', $userids)));
            if (!$userids) {
                return;
            }
            [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
            $select .= " AND userid $insql";
            $params += $inparams;
        }
        $origins = $DB->get_records_select_menu('sideline_aicall', $select, $params, '', 'id, actionid');
        if (!$origins) {
            return;
        }
        $fields = [];
        if ($DB->get_manager()->table_exists('ai_action_generate_text')) {
            $fields = array_values(array_intersect(self::TEXT_FIELDS, array_keys($DB->get_columns('ai_action_generate_text'))));
        }
        if (!$fields) {
            // Nothing can be emptied: keep the records of origin, so that a later request still finds the entries.
            debugging('mod_sideline: the text fields of the AI subsystem are unknown; nothing was emptied.', DEBUG_DEVELOPER);
            return;
        }
        foreach (array_chunk(array_values($origins), self::CHUNK) as $chunk) {
            [$insql, $inparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            $textids = $DB->get_fieldset_select(
                'ai_action_register',
                'actionid',
                "actionname = :action AND id $insql",
                $inparams + ['action' => 'generate_text']
            );
            if (!$textids) {
                continue;
            }
            [$textsql, $textparams] = $DB->get_in_or_equal($textids, SQL_PARAMS_NAMED);
            $set = implode(', ', array_map(static fn(string $f): string => "$f = ''", $fields));
            $DB->execute("UPDATE {ai_action_generate_text} SET $set WHERE id $textsql", $textparams);
        }
        // Exactly the records read above: a call attributed meanwhile keeps its record (its generation checks the
        // consent after attributing and empties its entry itself).
        foreach (array_chunk(array_keys($origins), self::CHUNK) as $chunk) {
            $DB->delete_records_list('sideline_aicall', 'id', $chunk);
        }
    }
}
