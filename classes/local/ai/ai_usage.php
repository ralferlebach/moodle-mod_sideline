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
 * Cost control for AI calls (P-25, P-31, ADR-08).
 *
 * Every call to the provider is counted, whether it succeeded or not and whether it generated or
 * translated: a cap on calls per hour and a cap on tokens per day, both site-wide. The ledger holds
 * no user, no context and no text, only the instance, the purpose, the outcome and the token count,
 * so it is not personal data. When a provider reports no token numbers, they are estimated from the
 * text length (about four characters per token), so the daily cap still works.
 *
 * Reservations are atomic site-wide (issue #8): the check of both caps and the ledger write happen
 * under one site-wide lock, so concurrent requests of different users and instances can never pass
 * the check together. A reservation counts the worst case (prompt estimate plus the largest possible
 * answer) until the call completes; a call that never completes (aborted process) keeps counting with
 * its worst case and stays visible as an open reservation (timecompleted 0). If the lock cannot be
 * obtained in time, no call is made (fail closed).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_usage {
    /** @var int Default cap of AI calls per site and hour. */
    public const DEFAULT_HOURLY_CAP = 200;

    /** @var string Purpose: phrasing commentary. */
    public const GENERATE = 'generate';
    /** @var string Purpose: the separate translation step. */
    public const TRANSLATE = 'translate';
    /** @var string Purpose: persona preview by an administrator. */
    public const PREVIEW = 'preview';

    /** @var int Ledger rows are kept this long, then the scheduled task deletes them. */
    public const KEEP = 31 * DAYSECS;

    /** @var int Tokens reserved for the answer until the provider reports the real number (worst case). */
    public const MAX_RESPONSE_TOKENS = 1024;

    /** @var int Seconds to wait for the site-wide reservation lock before giving up (no call then). */
    public const LOCK_TIMEOUT = 5;

    /**
     * Whether another call fits into both caps.
     *
     * @param int $now Current time.
     * @param int $calls Number of calls that are about to be made.
     * @param int $tokens Tokens the calls may use at most (0: only check that the cap is not reached yet).
     * @return bool
     */
    public static function within_budget(int $now, int $calls = 1, int $tokens = 0): bool {
        global $DB;

        $cap = (int) get_config('mod_sideline', 'aihourlycap');
        if ($cap <= 0) {
            $cap = self::DEFAULT_HOURLY_CAP;
        }
        $used = $DB->count_records_select('sideline_aiusage', 'timecreated > ?', [$now - HOURSECS]);
        if ($used + $calls > $cap) {
            return false;
        }
        $tokencap = (int) get_config('mod_sideline', 'aidailytokencap');
        if ($tokencap > 0) {
            $used = (int) $DB->get_field_sql(
                'SELECT COALESCE(SUM(tokens), 0) FROM {sideline_aiusage} WHERE timecreated > ?',
                [$now - DAYSECS]
            );
            if ($used >= $tokencap || $used + $tokens > $tokencap) {
                return false;
            }
        }
        return true;
    }

    /**
     * Reserve one call before it is made: checks both caps and writes the ledger row at once.
     *
     * Writing before the call (and not after the provider answered, seconds later) and doing check and
     * write under one site-wide lock keeps concurrent requests from all passing the check together. The
     * token number is the worst case (prompt estimate plus MAX_RESPONSE_TOKENS); complete() replaces it
     * with the provider's figure.
     *
     * @param int $sidelineid Instance id, 0 for previews.
     * @param string $purpose One of the purpose constants.
     * @param string $prompt The prompt, only for the estimate.
     * @param int $now Current time.
     * @param \core\lock\lock_factory|null $locks Lock factory, injectable for tests.
     * @return int|null Ledger row id, or null when a cap is reached or the lock could not be obtained.
     */
    public static function reserve(
        int $sidelineid,
        string $purpose,
        string $prompt,
        int $now,
        ?\core\lock\lock_factory $locks = null,
    ): ?int {
        global $DB;

        $worstcase = self::estimate($prompt, null) + self::MAX_RESPONSE_TOKENS;
        $locks = $locks ?? \core\lock\lock_config::get_lock_factory('mod_sideline');
        $lock = $locks->get_lock('aibudget', self::LOCK_TIMEOUT);
        if (!$lock) {
            // Fail closed: without the lock the caps cannot be guaranteed, so no call is made.
            return null;
        }
        try {
            if (!self::within_budget($now, 1, $worstcase)) {
                return null;
            }
            return (int) $DB->insert_record('sideline_aiusage', (object) [
                'sidelineid' => $sidelineid,
                'purpose' => $purpose,
                'success' => 0,
                'tokens' => $worstcase,
                'timecreated' => $now,
                'timecompleted' => 0,
            ]);
        } finally {
            $lock->release();
        }
    }

    /**
     * Complete a reserved call with its outcome.
     *
     * @param int $id Ledger row id from reserve().
     * @param bool $success Whether text came back.
     * @param int|null $tokens Tokens reported by the provider, null when none were reported.
     * @param string $prompt The prompt, only for the estimate.
     * @param string|null $response The response, only for the estimate.
     * @param int|null $now Completion time, defaults to now.
     */
    public static function complete(
        int $id,
        bool $success,
        ?int $tokens,
        string $prompt,
        ?string $response,
        ?int $now = null,
    ): void {
        global $DB;

        $DB->update_record('sideline_aiusage', (object) [
            'id' => $id,
            'success' => $success ? 1 : 0,
            'tokens' => max(0, $tokens ?? self::estimate($prompt, $response)),
            'timecompleted' => max(1, $now ?? time()),
        ]);
    }

    /**
     * Estimated tokens: about four characters per token.
     *
     * @param string $prompt Prompt.
     * @param string|null $response Response.
     * @return int
     */
    private static function estimate(string $prompt, ?string $response): int {
        return (int) ceil((strlen($prompt) + strlen((string) $response)) / 4);
    }

    /**
     * Usage summary for the settings page.
     *
     * @param int $now Current time.
     * @return array calls in the last hour, tokens in the last day, open reservations.
     */
    public static function summary(int $now): array {
        global $DB;

        return [
            'open' => $DB->count_records_select('sideline_aiusage', 'timecompleted = 0 AND timecreated > ?', [$now - DAYSECS]),
            'callshour' => $DB->count_records_select('sideline_aiusage', 'timecreated > ?', [$now - HOURSECS]),
            'tokensday' => (int) $DB->get_field_sql(
                'SELECT COALESCE(SUM(tokens), 0) FROM {sideline_aiusage} WHERE timecreated > ?',
                [$now - DAYSECS]
            ),
        ];
    }

    /**
     * Delete old ledger rows.
     *
     * @param int $now Current time.
     */
    public static function purge(int $now): void {
        global $DB;

        $DB->delete_records_select('sideline_aiusage', 'timecreated < ?', [$now - self::KEEP]);
    }
}
