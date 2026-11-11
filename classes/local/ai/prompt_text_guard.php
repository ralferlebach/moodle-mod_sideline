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
 * Checks configured free text before it may become part of an AI prompt (P-22, L-13, N-04).
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
 * Names of the people of a course are not loaded into memory: course_name() looks up only the words
 * of the text itself, in the database, so its cost depends on the length of the text (at most
 * MAX_LENGTH characters) and not on the number of people ever enrolled in the course.
 *
 * Limits: it recognises the forms listed in check() and the names of the course and of the learner.
 * A name of a person outside the course and identifiers in forms it does not know are not
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

    /** @var string[] Abbreviated month names of the same languages ("3 Nov", "Okt. 3"). */
    private const MONTH_ABBREVIATIONS = [
        'jan', 'feb', 'mar', 'apr', 'jun', 'jul', 'aug', 'sep', 'sept', 'oct', 'nov', 'dec', 'mär', 'okt', 'dez',
        'janv', 'févr', 'fevr', 'avr', 'juil', 'déc', 'ene', 'abr', 'ago', 'dic', 'gen', 'mag', 'giu', 'lug', 'ott',
        'mrt', 'mei', 'fev', 'set', 'out',
    ];

    /** @var string[] Phrases that address the model instead of the learner (prompt injection). */
    private const INJECTION = [
        'system prompt', 'systemprompt', 'jailbreak', 'developer mode', 'you are now', 'du bist jetzt', 'act as',
        'tu es maintenant', 'facts:', 'output format', 'persona:', 'language:', 'rules (', '"blocks"', "\x60\x60\x60",
    ];

    /**
     * @var string[] Patterns of instructions aimed at the model (prompt injection), in every language with a
     * language profile: a verb of ignoring near what is to be ignored, a new role or new instructions.
     */
    private const INJECTION_PATTERNS = [
        '/\b(ignore|disregard|forget|override|bypass|skip|discard|drop)\b.{0,40}'
            . '\b(instruction|rule|guidance|direction|prompt|above|previous|prior|earlier|everything|all|safety|system)/u',
        '/\b(ignorier\w*|vergiss|missachte\w*|umgeh\w*|übergeh\w*)\b.{0,40}'
            . '\b(anweisung|vorgabe|regel|alles|vorher|bisherig|oben|sicherheit|system)/u',
        '/\b(ignore[sz]?|oublie[sz]?|néglige[sz]?)\b.{0,40}\b(instruction|consigne|règle|regle|tout|précédent|precedent)/u',
        '/\b(ignora\w*|olvida\w*|omite|descarta\w*)\b.{0,40}\b(instrucci\w*|regla\w*|anterior\w*|todo|previ\w*)/u',
        '/\b(ignora\w*|dimentica\w*|tralascia\w*|scarta\w*)\b.{0,40}\b(istruzion\w*|regol\w*|precedent\w*|tutto|sopra)/u',
        '/\b(negeer\w*|vergeet)\b.{0,40}\b(instructie\w*|regel\w*|eerder\w*|vorig\w*|alles|boven)/u',
        '/\b(ignor[ae]\w*|esqueça\w*|esqueca\w*|desconsider\w*)\b.{0,40}\b(instru\w*|regra\w*|anterior\w*|tudo|acima)/u',
        '/\b(new|neue|andere|nouvelles?|nuevas?|nuov[ae]|nieuwe|novas?)\s+'
            . '(instruction|anweisung|rolle|role|rôle|consigne|instrucci|istruzion|instructie|instruç|instruc|regel|papel|ruolo)/u',
        '/\b(ahora eres|ora sei|adesso sei|je bent nu|agora (você|voce) é|agora es|tu es désormais)\b/u',
    ];

    /** @var int Most consecutive words a name may span (first names such as "Anna Lena", "van der Berg"). */
    public const NAME_WORDS = 4;

    /**
     * @var int Phrases per query. Each query reads the enrolments of the courses once, so one query is the aim: a text
     * of MAX_LENGTH characters has at most MAX_LENGTH / 2 words, and phrases of one letter do not count, which leaves
     * about 750 phrases at the very most (one-letter words, every sequence different; tools/bench_names.php, text
     * "letters"). Two lists of this length stay below the parameter limits of every database Moodle supports.
     */
    public const CHUNK = 750;

    /** @var int Results kept for the request, per course and text. */
    private const CACHE_SIZE = 200;

    /** @var array Results of course_name() for the request, keyed by course id and text hash. */
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
            // Any host name with a top-level domain ("discord.gg", "t.co/abc"), with or without a scheme.
            'url' => '~(https?://|ftp://|www\.|\b[\p{L}\p{N}-]+(\.[\p{L}\p{N}-]+)*\.\p{L}{2,24}\b)~iu',
            // E-mail addresses written around the filter: "anna(at)uni(dot)de", "anna at uni dot de".
            'obfuscated email' => '~([(\[{]\s*(at|ät|dot|punkt|point|punto)\s*[)\]}])|(\S\s+(at|ät)\s+\S+\s+(dot|punkt)\s+\S)~iu',
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
        // A day with a month, written out or abbreviated, with or without an ordinal ending ("November 3rd", "3. Nov.").
        $ordinal = '(st|nd|rd|th|er|e|º|ª)?\.?';
        foreach (array_merge(self::MONTHS, self::MONTH_ABBREVIATIONS) as $month) {
            $m = preg_quote($month, '/');
            if (
                preg_match('/\b\d{1,2}' . $ordinal . '\s*(of\s+|de\s+|di\s+)?' . $m . '\b|\b' . $m . '\.?\s+\d{1,2}'
                    . $ordinal . '(?!\d)/u', $lower)
            ) {
                return 'date';
            }
        }
        // A day as an ordinal after a preposition of time ("by the 3rd", "until the 21st").
        if (preg_match('/\b(on|by|until|till|before|after|from)\s+(the\s+)?\d{1,2}(st|nd|rd|th)\b/u', $lower)) {
            return 'date';
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
        return self::contains_name($withouttokens, $names) ? 'name' : null;
    }

    /**
     * Whether a text contains one of the given names as a whole word, ignoring case.
     *
     * @param string $text Text.
     * @param string[] $names Names.
     * @return bool
     */
    public static function contains_name(string $text, array $names): bool {
        $lower = mb_strtolower($text);
        foreach ($names as $name) {
            $name = mb_strtolower(trim((string) $name));
            if (mb_strlen($name) < 2) {
                continue;
            }
            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($name, '/') . '(?![\p{L}\p{N}])/u', $lower)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The learner's own names, the list check() compares against.
     *
     * @param \stdClass|null $user The learner.
     * @return string[]
     */
    public static function names(?\stdClass $user): array {
        $names = [];
        foreach (['firstname', 'lastname', 'middlename', 'alternatename'] as $field) {
            if ($user && trim((string) ($user->$field ?? '')) !== '') {
                $names[] = (string) $user->$field;
            }
        }
        return array_values(array_unique($names));
    }

    /**
     * Whether a free text contains the first or last name of somebody enrolled in one of some courses.
     *
     * Everybody enrolled counts, active or not, in every role. Every phrase of one to NAME_WORDS
     * consecutive words of the text is compared, case-insensitively, with the first and last names in
     * the database; nothing else of the course is read. A text longer than MAX_LENGTH gives "length",
     * a database error "budget": the text is then not sent.
     *
     * @param string $text Free text, placeholders removed.
     * @param int|int[] $courseids Course ids; none checks nothing.
     * @return string|null 'name', 'length', 'budget' or null when no name of the courses was found.
     */
    public static function course_name(string $text, int|array $courseids): ?string {
        global $DB;

        $courseids = array_map('intval', (array) $courseids);
        $courseids = array_values(array_unique(array_filter($courseids, static fn(int $id): bool => $id > 0)));
        if (!$courseids) {
            return null;
        }
        sort($courseids);
        if (mb_strlen($text) > self::MAX_LENGTH) {
            return 'length';
        }
        $candidates = self::candidates($text);
        if (!$candidates) {
            return null;
        }
        $key = implode(',', $courseids) . ':' . sha1(implode("\n", $candidates));
        if (array_key_exists($key, self::$namecache)) {
            return self::$namecache[$key];
        }
        $result = null;
        try {
            $enrolids = self::enrol_ids($courseids);
            foreach ($enrolids ? array_chunk($candidates, self::CHUNK) : [] as $chunk) {
                [$sql, $params] = self::name_query($enrolids, $chunk);
                if ($DB->record_exists_sql($sql, $params)) {
                    $result = 'name';
                    break;
                }
            }
        } catch (\dml_exception $e) {
            // Unknown whether a name is in the text: it does not leave the site.
            return 'budget';
        }
        if (count(self::$namecache) >= self::CACHE_SIZE) {
            self::$namecache = [];
        }
        self::$namecache[$key] = $result;
        return $result;
    }

    /**
     * The enrolment methods of the courses. course_name() reads them first, so that the name query starts from the
     * enrolments of these courses (index enrolid, userid) whatever the planner estimates: on PostgreSQL with a
     * million enrolments elsewhere on the site, a join with enrol let the planner scan all enrolments (#24).
     *
     * @param int[] $courseids Course ids, at least one.
     * @return int[] Ids in enrol, possibly none.
     */
    public static function enrol_ids(array $courseids): array {
        global $DB;

        [$incourse, $params] = $DB->get_in_or_equal($courseids);
        return array_map('intval', $DB->get_fieldset_select('enrol', 'id', "courseid $incourse", $params));
    }

    /**
     * The query of course_name() for one chunk of phrases: whether somebody enrolled by one of the enrolment methods
     * has one of them as first or last name. Public for the execution plan in tools/bench_names.php.
     *
     * @param int[] $enrolids Enrolment methods of the courses (enrol_ids()), at least one.
     * @param string[] $phrases Phrases in lower case, at most CHUNK.
     * @return array [sql, params]
     */
    public static function name_query(array $enrolids, array $phrases): array {
        global $DB;

        [$inenrol, $enrolparams] = $DB->get_in_or_equal($enrolids, SQL_PARAMS_NAMED, 'sge');
        [$first, $firstparams] = $DB->get_in_or_equal($phrases, SQL_PARAMS_NAMED, 'sgf');
        [$last, $lastparams] = $DB->get_in_or_equal($phrases, SQL_PARAMS_NAMED, 'sgl');
        $sql = "SELECT 1
                  FROM {user_enrolments} ue
                  JOIN {user} u ON u.id = ue.userid
                 WHERE ue.enrolid $inenrol AND u.deleted = 0
                       AND (LOWER(TRIM(u.firstname)) $first OR LOWER(TRIM(u.lastname)) $last)";
        return [$sql, $enrolparams + $firstparams + $lastparams];
    }

    /**
     * Every phrase of one to NAME_WORDS consecutive words of a text, lower case, at least two characters.
     *
     * A phrase runs from the start of a word to the end of a later word with the text in between as
     * written, so "jean-luc" yields "jean", "luc" and "jean-luc". Words are runs of letters, marks and
     * digits; everything else separates them.
     *
     * @param string $text Free text of at most MAX_LENGTH characters.
     * @return string[]
     */
    public static function candidates(string $text): array {
        $lower = mb_strtolower($text);
        preg_match_all('/[\p{L}\p{M}\p{N}]+/u', $lower, $words, PREG_OFFSET_CAPTURE);
        $words = $words[0];
        $candidates = [];
        foreach ($words as $i => [, $start]) {
            for ($j = $i; $j < min(count($words), $i + self::NAME_WORDS); $j++) {
                $end = $words[$j][1] + strlen($words[$j][0]);
                $phrase = substr($lower, $start, $end - $start);
                if (mb_strlen($phrase) >= 2) {
                    $candidates[$phrase] = true;
                }
            }
        }
        return array_keys($candidates);
    }

    /**
     * Forget the names kept for the request (tests, long running scripts).
     */
    public static function reset(): void {
        self::$namecache = [];
    }
}
