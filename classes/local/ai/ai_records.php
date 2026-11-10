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
     * Record the entry a call caused: after $after, for this person and context, with this prompt and, when the
     * provider answered, with the provider's id of this answer.
     *
     * The lock of the person (lock()) keeps the plugin's own calls apart, but not those of another plugin: a call of
     * another plugin for the same person in the same context with the very same prompt can be registered in the
     * same moment. The provider's answer id tells them apart. When it does not (no answer, or several entries still
     * match), nothing is recorded: an entry of another plugin is never taken for the plugin's own (fail-closed); the
     * own entry then stays with the subsystem, as one of another plugin would.
     *
     * @param int $sidelineid Instance id.
     * @param int $userid User id.
     * @param int $contextid Context the call was registered in.
     * @param int $after Highest register id before the call.
     * @param string $prompt Prompt that was sent.
     * @param int $now Current time.
     * @param string|null $responseid The provider's id of the answer, null without an answer.
     * @return int Number of recorded entries (0 or 1).
     */
    public static function attribute(
        int $sidelineid,
        int $userid,
        int $contextid,
        int $after,
        string $prompt,
        int $now,
        ?string $responseid = null,
    ): int {
        global $DB;

        $rows = $DB->get_records_sql(
            "SELECT r.id, t.prompt, t.responseid
               FROM {ai_action_register} r
               JOIN {ai_action_generate_text} t ON t.id = r.actionid
              WHERE r.actionname = :action AND r.userid = :userid AND r.contextid = :contextid AND r.id > :after",
            ['action' => 'generate_text', 'userid' => $userid, 'contextid' => $contextid, 'after' => $after]
        );
        $matches = array_filter($rows, static fn($row): bool => (string) $row->prompt === $prompt
            && ($responseid === null || $responseid === '' || (string) $row->responseid === $responseid));
        $matches = array_filter(
            $matches,
            static fn($row): bool => !$DB->record_exists('sideline_aicall', ['actionid' => $row->id])
        );
        if (count($matches) !== 1) {
            debugging('mod_sideline: the entry of an AI call was ' . ($matches ? 'not unique' : 'not found')
                . '; it is not recorded and cannot be emptied on withdrawal.', DEBUG_DEVELOPER);
            return 0;
        }
        $DB->insert_record('sideline_aicall', (object) ['sidelineid' => $sidelineid, 'userid' => $userid,
            'actionid' => reset($matches)->id, 'timecreated' => $now], false);
        return 1;
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
        self::forget_select($select, $params);
    }

    /**
     * Retention: empty what the subsystem kept of the plugin's calls older than a moment, in every instance.
     *
     * The subsystem itself never deletes. The register rows (time, provider, tokens) stay, as after its own deletion.
     *
     * @param int $before Calls attributed before this time.
     * @return int Number of emptied entries.
     */
    public static function forget_older_than(int $before): int {
        return self::forget_select('timecreated < :before', ['before' => $before]);
    }

    /**
     * Entries in users' contexts that have the form of the plugin's prompts but no record of origin, made before the
     * upgrade to 0.4.3: calls of 0.4.1, which registered them in the user context before origins were recorded (and
     * calls of 0.4.2 whose attribution failed). They belong to no instance. Later entries are never included, so that
     * a call in flight, whose record of origin is written after the provider's answer, is not touched. A site
     * installed with 0.4.3 or later has none.
     *
     * The prompts of these older entries are read to recognise their form; nothing of them is returned.
     *
     * @return int[] Ids in ai_action_generate_text.
     */
    public static function unattributed(): array {
        global $DB;

        $before = (int) get_config('mod_sideline', 'aiattributionsince');
        if (!$before || !$DB->get_manager()->table_exists('ai_action_register')) {
            return [];
        }
        $ids = [];
        $rs = $DB->get_recordset_sql(
            "SELECT t.id, t.prompt
               FROM {ai_action_register} r
               JOIN {ai_action_generate_text} t ON t.id = r.actionid
               JOIN {context} ctx ON ctx.id = r.contextid AND ctx.contextlevel = :level
              WHERE r.actionname = :action AND r.timecreated < :before
                AND NOT EXISTS (SELECT 1 FROM {sideline_aicall} a WHERE a.actionid = r.id)",
            ['level' => CONTEXT_USER, 'action' => 'generate_text', 'before' => $before]
        );
        foreach ($rs as $row) {
            if (self::is_plugin_prompt((string) $row->prompt)) {
                $ids[] = (int) $row->id;
            }
        }
        $rs->close();
        return $ids;
    }

    /**
     * Empty the unattributed entries of 0.4.1 (unattributed()): an action of the site administrator, because they
     * cannot be told apart by instance and a withdrawal in one instance cannot reach them.
     *
     * @return int Number of emptied entries.
     */
    public static function forget_unattributed(): int {
        return self::empty_texts(self::unattributed());
    }

    /**
     * Empty the entries of the records of origin that match, then delete exactly these records.
     *
     * @param string $select Condition on sideline_aicall.
     * @param array $params Parameters.
     * @return int Number of emptied entries.
     */
    private static function forget_select(string $select, array $params): int {
        global $DB;

        $origins = $DB->get_records_select_menu('sideline_aicall', $select, $params, '', 'id, actionid');
        if (!$origins) {
            return 0;
        }
        if (!self::text_fields()) {
            // Nothing can be emptied: keep the records of origin, so that a later request still finds the entries.
            debugging('mod_sideline: the text fields of the AI subsystem are unknown; nothing was emptied.', DEBUG_DEVELOPER);
            return 0;
        }
        $count = 0;
        foreach (array_chunk(array_values($origins), self::CHUNK) as $chunk) {
            [$insql, $inparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            $textids = $DB->get_fieldset_select(
                'ai_action_register',
                'actionid',
                "actionname = :action AND id $insql",
                $inparams + ['action' => 'generate_text']
            );
            $count += self::empty_texts(array_map('intval', $textids));
        }
        // Exactly the records read above: a call attributed meanwhile keeps its record (its generation checks the
        // consent after attributing and empties its entry itself).
        foreach (array_chunk(array_keys($origins), self::CHUNK) as $chunk) {
            $DB->delete_records_list('sideline_aicall', 'id', $chunk);
        }
        return $count;
    }

    /**
     * The fields of ai_action_generate_text that hold text of the person, on this Moodle version.
     *
     * @return string[]
     */
    private static function text_fields(): array {
        global $DB;

        if (!$DB->get_manager()->table_exists('ai_action_generate_text')) {
            return [];
        }
        return array_values(array_intersect(self::TEXT_FIELDS, array_keys($DB->get_columns('ai_action_generate_text'))));
    }

    /**
     * Empty the text fields of entries of ai_action_generate_text.
     *
     * @param int[] $textids Ids in ai_action_generate_text.
     * @return int Number of entries.
     */
    private static function empty_texts(array $textids): int {
        global $DB;

        $fields = self::text_fields();
        if (!$textids || !$fields) {
            return 0;
        }
        $set = implode(', ', array_map(static fn(string $f): string => "$f = ''", $fields));
        foreach (array_chunk($textids, self::CHUNK) as $chunk) {
            [$textsql, $textparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            $DB->execute("UPDATE {ai_action_generate_text} SET $set WHERE id $textsql", $textparams);
        }
        return count($textids);
    }
}
