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
 * Checks configured free text before it may become part of an AI prompt (issue #4, P-22, L-13, N-04).
 *
 * Free text that a teacher or administrator wrote (the intent of a custom rule, the style of a
 * persona) is not a checked fact. It may contain identifiers that must stay on the site: e-mail
 * addresses, links and domains, phone numbers, exact dates and times, numeric identifiers such as
 * course or module ids, @handles, names of people of the course, or instructions aimed at the model.
 *
 * The guard does not try to repair such text, because a partial repair can still leak (a name
 * written in an unexpected form, a link split by spaces). It only answers whether the text is
 * free of everything it recognises; when it is not, the caller sends nothing and serves the
 * localised template text (static fallback). The tokens that the plugin issues itself, such as
 * [anonmod1], are allowed; every other bracketed token is not.
 *
 * Limits: it recognises the forms listed in check() and the names it is given. A name it is not
 * given (a person outside the course) and identifiers in forms it does not know are not
 * recognised. That residual risk is documented in docs/privacy/DATENSCHUTZ.md.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class prompt_text_guard {
    /** @var int Longest free text that may be sent at all. */
    public const MAX_LENGTH = 500;

    /** @var string[] Month names of the languages with a language profile, for exact dates. */
    private const MONTHS = [
        'january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october',
        'november', 'december', 'januar', 'februar', 'märz', 'maerz', 'juni', 'juli', 'oktober', 'dezember',
        'janvier', 'février', 'fevrier', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'aout', 'septembre',
        'octobre', 'novembre', 'décembre', 'decembre', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
        'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre', 'gennaio', 'febbraio', 'aprile',
        'maggio', 'giugno', 'luglio', 'settembre', 'ottobre', 'dicembre', 'januari', 'februari', 'maart',
        'augustus', 'janeiro', 'fevereiro', 'março', 'marco', 'maio', 'junho', 'julho', 'setembro', 'outubro',
        'novembro', 'dezembro',
    ];

    /** @var string[] Phrases that address the model instead of the learner (prompt injection). */
    private const INJECTION = [
        'system prompt', 'systemprompt', 'jailbreak', 'developer mode', 'you are now', 'du bist jetzt', 'act as',
        'tu es maintenant', 'facts:', 'output format', 'persona:', 'language:', 'rules (', '"blocks"', "\x60\x60\x60",
    ];

    /** @var string[] Patterns of instructions aimed at the model (prompt injection). */
    private const INJECTION_PATTERNS = [
        '/\b(ignore|disregard|forget|override|bypass)\b.{0,40}\b(instruction|rule|above|previous|prior|everything|all|safety)/u',
        '/\b(ignorier\w*|vergiss|missachte\w*|umgeh\w*)\b.{0,40}\b(anweisung|regel|alles|vorher|oben|sicherheit)/u',
        '/\b(ignore[sz]?|oublie[sz]?)\b.{0,40}\b(instruction|règle|regle|tout|précédent)/u',
        '/\b(new|neue|andere)\s+(instruction|anweisung|rolle|role)/u',
    ];

    /** @var array Names per course, kept for the request. */
    private static array $namecache = [];

    /**
     * Why a free text may not be sent, or null when it may.
     *
     * @param string $text The free text as written; placeholders such as {label} are removed by the caller.
     * @param string[] $names Names that must not appear (people of the course, the learner).
     * @param string[] $tokens Tokens the plugin issued for this request; all others are refused.
     * @return string|null Reason code, or null when nothing was found.
     */
    public static function check(string $text, array $names = [], array $tokens = []): ?string {
        if (mb_strlen($text) > self::MAX_LENGTH) {
            return 'length';
        }
        // Brackets that are not one of our own tokens: could be a fake token or markup.
        $withouttokens = str_replace($tokens, ' ', $text);
        if (preg_match('/[\[\]{}<>]/u', $withouttokens)) {
            return 'markup';
        }
        $lower = mb_strtolower($withouttokens);
        $checks = [
            'email' => '/[^\s@]+@[^\s@]+\.[a-z]{2,}/iu',
            'handle' => '/(^|\s)@[\p{L}\p{N}_.-]{2,}/u',
            'url' => '~(https?://|ftp://|www\.|\b[\p{L}\p{N}-]+\.(com|org|net|edu|de|at|ch|eu|fr|es|it|nl|pt|uk|io|info|ly)\b)~iu',
            'ip' => '/\b\d{1,3}(\.\d{1,3}){3}\b/',
            'date' => '~\b\d{1,4}[./-]\d{1,2}([./-]\d{2,4})?\b~u',
            'time' => '/\b\d{1,2}[:h]\d{2}\b/u',
            'phone' => '/\+?\d[\d\s()\/-]{5,}\d/u',
            'number' => '/\d{3,}/u',
            'id' => '/\b(id|cmid|courseid|userid|matrikel\w*|student number)\s*[:=#]?\s*\d/iu',
        ];
        foreach ($checks as $reason => $pattern) {
            if (preg_match($pattern, $withouttokens)) {
                return $reason;
            }
        }
        foreach (self::MONTHS as $month) {
            $m = preg_quote($month, '/');
            if (preg_match('/\b\d{1,2}\.?\s*' . $m . '\b|\b' . $m . '\s+\d{1,2}\b/u', $lower)) {
                return 'date';
            }
        }
        foreach (self::INJECTION as $phrase) {
            if (str_contains($lower, $phrase)) {
                return 'injection';
            }
        }
        foreach (self::INJECTION_PATTERNS as $pattern) {
            if (preg_match($pattern, $lower)) {
                return 'injection';
            }
        }
        foreach ($names as $name) {
            $name = mb_strtolower(trim((string) $name));
            if (mb_strlen($name) < 2) {
                continue;
            }
            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($name, '/') . '(?![\p{L}\p{N}])/u', $lower)) {
                return 'name';
            }
        }
        return null;
    }

    /**
     * Names of the people of a course and of the learner, the list check() compares against.
     *
     * First and last names of everybody enrolled in the course (active or not, every role) plus the
     * learner's own. One set-based query; the result is kept for the request.
     *
     * @param int $courseid Course id, 0 for none (persona preview).
     * @param \stdClass|null $user The learner.
     * @return string[]
     */
    public static function names(int $courseid, ?\stdClass $user = null): array {
        global $DB;

        $names = [];
        if ($user) {
            foreach (['firstname', 'lastname', 'middlename', 'alternatename'] as $field) {
                if (!empty($user->$field)) {
                    $names[] = (string) $user->$field;
                }
            }
        }
        if ($courseid > 0) {
            if (!isset(self::$namecache[$courseid])) {
                $rs = $DB->get_recordset_sql(
                    "SELECT DISTINCT u.id, u.firstname, u.lastname
                       FROM {user} u
                       JOIN {user_enrolments} ue ON ue.userid = u.id
                       JOIN {enrol} e ON e.id = ue.enrolid
                      WHERE e.courseid = :courseid AND u.deleted = 0",
                    ['courseid' => $courseid]
                );
                self::$namecache[$courseid] = [];
                foreach ($rs as $row) {
                    self::$namecache[$courseid][] = (string) $row->firstname;
                    self::$namecache[$courseid][] = (string) $row->lastname;
                }
                $rs->close();
            }
            $names = array_merge($names, self::$namecache[$courseid]);
        }
        return array_values(array_unique(array_filter($names, static fn(string $n): bool => trim($n) !== '')));
    }

    /**
     * Forget the names kept for the request (tests, long running scripts).
     */
    public static function reset(): void {
        self::$namecache = [];
    }
}
