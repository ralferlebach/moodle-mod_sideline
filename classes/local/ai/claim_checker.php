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

use mod_sideline\local\model\recommendation;

/**
 * Semantic plausibility check of generated text against the facts (P-09, N-03).
 *
 * The structural checks in response_validator stop invented numbers, links and names. They do not
 * stop a sentence such as "you passed" for a failed quiz, or "it is unlocked now" for a locked
 * activity. This class recognises such statements ("claims") and accepts a claim only where the
 * rule that produced the block makes it true: no invented pass or fail, no invented unlocking,
 * no invented deadline, grade, retry or course completion, and no reference to a link where no
 * action is offered.
 *
 * The recognition works on phrase lists per language and is deliberately conservative: a false
 * alarm costs only the AI phrasing, because the caller then serves the verified template text.
 * Negated phrases ("not passed", "noch nicht geöffnet") are matched before their positive forms
 * and removed from the text, so that the positive form is not found inside them.
 *
 * Supported languages are those with phrase lists. For other languages the gateway generates in
 * English, checks the English text here, and translates it in a separate, checked step (P-24).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class claim_checker {
    /** @var string Claim: the activity was failed. */
    public const FAIL = 'fail';
    /** @var string Claim: no attempt is left. */
    public const NORETRY = 'noretry';
    /** @var string Claim: another attempt is possible. */
    public const RETRY = 'retry';
    /** @var string Claim: the activity was passed. */
    public const PASS = 'pass';
    /** @var string Claim: the whole course is complete. */
    public const COURSECOMPLETE = 'coursecomplete';
    /** @var string Claim: something is not completed yet. */
    public const NOTDONE = 'notdone';
    /** @var string Claim: the learner completed something. */
    public const DONE = 'done';
    /** @var string Claim: something is locked or opens later. */
    public const LOCKED = 'locked';
    /** @var string Claim: something is open or unlocked now. */
    public const UNLOCKED = 'unlocked';
    /** @var string Claim: a deadline or due date. */
    public const DEADLINE = 'deadline';
    /** @var string Claim: grades, points or scores. */
    public const GRADE = 'grade';
    /** @var string Claim: something was submitted. */
    public const SUBMITTED = 'submitted';
    /** @var string Claim: a link or button to use. */
    public const ACTION = 'action';
    /** @var string Claim: a success ("successfully", "geschafft"), more than a completion. */
    public const SUCCESS = 'success';
    /** @var string Claim: a time span or a day in words ("in two days", "morgen"), which no rule states. */
    public const TIMESPAN = 'timespan';

    /**
     * Phrase patterns per language, in matching order. Negated and compound phrases come first.
     *
     * @var array
     */
    private const PATTERNS = [
        'en' => [
            self::NORETRY => [
                '\bno\s+(?:more\s+|further\s+)?(?:attempts?|tries)\s+(?:left|remaining)\b',
                '\bno\s+more\s+(?:attempts?|tries)\b',
                '\b(?:last|final)\s+(?:attempt|try)\b',
                '\bcannot\s+(?:try|attempt)\s+again\b',
            ],
            self::DEADLINE => [
                '\bhas\s+(?:already\s+)?passed\b',
                '\bdeadline',
                '\bdue\b',
                '\boverdue\b',
                '\bexpir\w*',
                '\btoo\s+late\b',
                '\btime\s+is\s+running\b',
                '\bdate\s+set\b',
                '\bcut-?off\b',
            ],
            self::TIMESPAN => [
                '\b(?:a|an|one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve|a\s+few|several)\s+'
                    . '(?:more\s+)?(?:minutes?|hours?|days?|weeks?|months?)\b',
                '\btomorrow\b',
                '\bday\s+after\s+tomorrow\b',
                '\bnext\s+(?:week|month|monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b',
                '\btonight\b',
            ],
            self::FAIL => [
                '\bfail\w*',
                '\bnot\s+pass\w*',
                '\bdid(?:n\'t|\s+not)\s+pass\b',
                '\bpass(?:ing)?\s+mark\b',
                '\bpass(?:ing)?\s+grade\b',
                '\bpass(?:ing)?\s+score\b',
                '\bpass(?:ing)?\s+threshold\b',
            ],
            self::COURSECOMPLETE => [
                '\b(?:completed|finished|done\s+with|mastered)\s+the\s+(?:whole\s+|entire\s+)?course\b',
                '\bcourse\s+(?:is\s+)?(?:now\s+)?(?:complete|completed|finished|done)\b',
            ],
            self::NOTDONE => [
                '\bnot\s+(?:yet\s+)?(?:marked\s+as\s+)?(?:completed?|finished|done)\b',
                '\b(?:haven\'t|have\s+not|hasn\'t|has\s+not)\s+(?:yet\s+)?(?:completed|finished)\b',
                '\bincomplete\b',
                '\bunfinished\b',
            ],
            self::SUCCESS => [
                '\bsuccess\w*',
                '\bmastered\b',
                '\bnailed\b',
                '\baced\b',
                '\bcrushed\s+it\b',
            ],
            self::LOCKED => [
                '\bnot\s+(?:yet\s+)?(?:open|opened|available|unlocked|accessible)\b',
                '\blocked\b',
                '\bclosed\b',
                '\bopens\b',
                '\bbecomes?\s+available\b',
                '\bwill\s+(?:open|unlock|be\s+(?:open|available|unlocked))\b',
                '\bunlocks\b',
                '\brequirements?\b',
                '\bprerequisites?\b',
            ],
            self::RETRY => [
                '\banother\s+(?:attempt|try|go)\b',
                '\btry\s+(?:it\s+)?again\b',
                '\bretry\b',
                '\bre-?take\b',
                '\bre-?attempt\b',
                '\bone\s+more\s+(?:attempt|try)\b',
                '\battempts?\s+(?:left|remaining)\b',
                '\bnext\s+attempt\b',
                '\bsecond\s+(?:attempt|chance)\b',
            ],
            self::PASS => [
                '\bpass(?:ed|es)?\b',
            ],
            self::UNLOCKED => [
                '\bunlocked\b',
                '\b(?:is|are)\s+(?:now\s+)?(?:open|available|accessible)\b',
                '\bnow\s+(?:open|available)\b',
                '\bopen\s+(?:for|to)\s+you\b',
                '\bready\s+for\s+you\b',
                '\bwaiting\s+for\s+you\b',
                '\bcan\s+now\s+(?:start|begin|open|work\s+on)\b',
                '\bcan\s+(?:start|begin|open)\s+(?:it\s+|\[anon[a-z]*\d*\]\s+)?(?:now|right\s+away|right\s+now|today)\b',
                '\b(?:start|begin|go)\s+(?:now|right\s+away)\b',
            ],
            self::SUBMITTED => [
                '\bsubmitted\b',
                '\bsubmission\b',
                '\bhanded\s+in\b',
                '\bturned\s+in\b',
            ],
            self::DONE => [
                '\byou(?:\'ve|\s+have)?\s+(?:already\s+|now\s+|just\s+)?(?:completed|finished|done)\b',
                '\bcompleted\b',
            ],
            self::GRADE => [
                '\bgrades?\b',
                '\bgraded\b',
                '\bgrading\b',
                '\bscores?\b',
                '\bscored\b',
                '\bpoints?\b',
                '\bmarks?\b',
                '\bpercent\w*',
                '%',
            ],
            self::ACTION => [
                '\bclick\w*',
                '\bbutton\b',
                '\blink\b',
                '\btap\b',
            ],
        ],
        'de' => [
            self::NORETRY => [
                'kein(?:e|en)?\s+(?:weiteren?\s+)?versuch\w*\s+mehr',
                'keine?n?\s+versuch\w*\s+(?:mehr\s+)?übrig',
                'letzte[nrs]?\s+versuch',
                'nicht\s+(?:mehr\s+)?wiederholen',
            ],
            self::DEADLINE => [
                'frist',
                'fällig',
                'abgabetermin',
                'termin',
                'verstrichen',
                'abgelaufen',
                'läuft\s+(?:bald\s+)?ab',
                'verspätet',
                'zu\s+spät',
                'stichtag',
                'deadline',
            ],
            self::TIMESPAN => [
                '\b(?:einer?|einem|einen|zwei|drei|vier|fünf|sechs|sieben|acht|neun|zehn|elf|zwölf'
                    . '|wenigen|ein\s+paar|mehreren)\s+'
                    . '(?:weiteren?\s+)?(?:minuten?|stunden?|tage?n?|wochen?|monate?n?)\b',
                '\bübermorgen\b',
                '\bmorgen\b',
                '\bnächste[nr]?\s+(?:woche|monat|montag|dienstag|mittwoch|donnerstag|freitag|samstag|sonntag)\b',
                '\bheute\s+abend\b',
            ],
            self::FAIL => [
                'nicht\s+bestanden',
                'durchgefallen',
                'bestehensgrenze',
                'bestehensnote',
                'nicht\s+geschafft',
                'misslungen',
            ],
            self::COURSECOMPLETE => [
                '\bkurs\s+(?:\p{L}+\s+){0,2}(?:abgeschlossen|geschafft|beendet|absolviert)',
                'kursabschluss',
            ],
            self::NOTDONE => [
                'noch\s+nicht\s+(?:\p{L}+\s+){0,2}(?:abgeschlossen|erledigt|fertig|geschafft|beendet)',
                'nicht\s+(?:als\s+)?abgeschlossen',
                'unvollständig',
                'unerledigt',
            ],
            self::SUCCESS => [
                'erfolgreich',
                '\berfolg\w*',
                '\bgeschafft\b',
                '\bgemeistert\b',
                '\bbravourös\b',
            ],
            self::LOCKED => [
                'noch\s+nicht\s+(?:geöffnet|offen|verfügbar|freigeschaltet|freigegeben|zugänglich)',
                '\bgesperrt\b',
                '\bgeschlossen\b',
                'öffnet',
                'wird\s+(?:\p{L}+\s+|\[anon[a-z]*\d*\]\s+){0,3}(?:verfügbar|geöffnet|freigeschaltet|freigegeben)',
                'freischalte\w*',
                'voraussetzung\w*',
            ],
            self::RETRY => [
                'noch\s+(?:einen|einmal\s+einen|eine\s+weitere[nr]?)\s+versuch',
                'weitere[nr]?\s+versuch',
                'erneute[nr]?\s+versuch',
                'neue[nr]?\s+versuch',
                'nochmal',
                'noch\s+einmal\s+versuch\w*',
                'wiederhol\w*',
                'zweite\s+chance',
            ],
            self::PASS => [
                '\bbestanden\b',
                '\bbestehst\b',
            ],
            self::UNLOCKED => [
                'freigeschaltet',
                'freigegeben',
                'jetzt\s+(?:offen|verfügbar|geöffnet|zugänglich)',
                '\bist\s+(?:jetzt\s+|nun\s+)?(?:offen|verfügbar|geöffnet|zugänglich)',
                'steht\s+(?:dir\s+)?(?:jetzt\s+)?offen',
                'wartet\s+auf\s+dich',
                '(?:jetzt|nun|ab\s+sofort|bereits|schon)\s+zur\s+verfügung',
                'kannst\s+(?:du\s+)?(?:jetzt|nun|sofort|direkt)\b',
                '\b(?:jetzt|sofort)\s+(?:starten|loslegen|beginnen|anfangen)\b',
            ],
            self::SUBMITTED => [
                'abgegeben',
                'eingereicht',
                'abgabe\b',
            ],
            self::DONE => [
                '\bdu\s+hast\b(?:[^.!?]*)\b(?:abgeschlossen|erledigt|beendet)',
                '\b(?:abgeschlossen|erledigt)\b',
            ],
            self::GRADE => [
                '\bnote\b',
                '\bnoten\b',
                '\bpunkte?\b',
                'punktzahl',
                'prozent',
                '%',
                'bewertung\w*',
                'bewertet',
                'benotet',
                'ergebnis\w*',
            ],
            self::ACTION => [
                'klick\w*',
                'schaltfläche',
                'button',
                '\blink\b',
                'tipp\s+auf',
            ],
        ],
    ];

    /** @var array Claims that each built-in rule may make, beyond those derived from facts. */
    private const ALLOWED = [
        'deadline_soon' => [self::DEADLINE, self::NOTDONE],
        'deadline_passed' => [self::DEADLINE, self::NOTDONE],
        'locked_opens' => [self::LOCKED],
        'locked_completion' => [self::LOCKED, self::DONE, self::NOTDONE],
        'locked_grade' => [self::LOCKED, self::GRADE],
        'locked_group' => [self::LOCKED],
        'locked_requirements' => [self::LOCKED],
        'not_passed' => [self::FAIL, self::GRADE, self::DONE],
        'not_passed_retry' => [self::FAIL, self::GRADE, self::DONE, self::RETRY],
        'not_passed_final' => [self::FAIL, self::GRADE, self::DONE, self::NORETRY],
        'success' => [self::DONE, self::SUCCESS],
        'success_next' => [self::DONE, self::NOTDONE, self::SUCCESS],
        'welcome_back' => [self::NOTDONE],
        'welcome_back_gentle' => [self::NOTDONE],
        'small_step' => [self::NOTDONE, self::TIMESPAN],
        'progress' => [self::DONE, self::NOTDONE, self::SUCCESS],
        'course_complete' => [self::COURSECOMPLETE, self::DONE, self::SUCCESS],
        'opens_later' => [self::LOCKED, self::NOTDONE],
        'submitted_waiting' => [self::SUBMITTED, self::GRADE, self::NOTDONE, self::SUCCESS],
        'smalltalk' => [],
        'generic' => [],
    ];

    /**
     * Whether claims can be checked in this language.
     *
     * @param string $lang Moodle language code or family.
     * @return bool
     */
    public static function supports(string $lang): bool {
        return isset(self::PATTERNS[language_detector::base_language($lang)]);
    }

    /**
     * The claims a text makes.
     *
     * @param string $text Text, tokens may still be in place.
     * @param string $lang Language of the text.
     * @return string[] Claim constants, unique.
     */
    public static function claims(string $text, string $lang): array {
        $patterns = self::PATTERNS[language_detector::base_language($lang)] ?? null;
        if ($patterns === null) {
            return [];
        }
        $text = str_replace(["\u{2019}", "\u{2018}"], "'", \core_text::strtolower($text));
        $found = [];
        foreach ($patterns as $claim => $alternatives) {
            $pattern = implode('|', $alternatives);
            $text = preg_replace_callback('/(*UCP)' . $pattern . '/iu', static function () use (&$found, $claim): string {
                $found[$claim] = true;
                return ' ';
            }, $text);
        }
        return array_keys($found);
    }

    /**
     * The claims a block may make.
     *
     * @param recommendation $rec The recommendation the block belongs to.
     * @param string $lang Language of the generated text, for reading the intent of custom rules.
     * @return string[]
     */
    public static function allowed(recommendation $rec, string $lang): array {
        $allowed = self::ALLOWED[$rec->key] ?? [];
        if (($rec->facts['outcome'] ?? '') === 'passed') {
            $allowed[] = self::PASS;
        }
        if (!empty($rec->facts['deadline'])) {
            $allowed[] = self::DEADLINE;
        }
        if (!empty($rec->facts['deadline']) || !empty($rec->facts['opensat'])) {
            // The prompt carries a coarse relative category, so "in two days" can be true; otherwise it is invented.
            $allowed[] = self::TIMESPAN;
        }
        if ($rec->actiontype === recommendation::ACTION_OPEN && $rec->targetcmid) {
            // The target is visible and available, otherwise the rule would not offer the action.
            $allowed[] = self::ACTION;
            $allowed[] = self::UNLOCKED;
        }
        if (str_starts_with($rec->key, 'group_')) {
            // Others completed something; the learner's own state is not part of a group statement.
            $allowed[] = self::DONE;
            $allowed[] = self::SUCCESS;
        }
        // Custom rules: whatever the teacher's own template states may be stated (in any supported language).
        if (!empty($rec->facts['templates']) && is_array($rec->facts['templates'])) {
            foreach ($rec->facts['templates'] as $tlang => $template) {
                $allowed = array_merge($allowed, self::claims((string) $template, (string) $tlang));
            }
        }
        return array_values(array_unique($allowed));
    }

    /**
     * Check generated texts in every language that has phrase lists.
     *
     * A provider may answer one block in another language than the rest. Checking every block in every
     * language means that a claim is found whatever language its block happens to be in.
     *
     * @param string[] $texts Text per block key, tokens still in place.
     * @param recommendation[] $recommendations The recommendations of the blocks.
     * @return string|null Null when every text is plausible, otherwise the first offending claim as "key:claim".
     */
    public static function check_all(array $texts, array $recommendations): ?string {
        foreach (array_keys(self::PATTERNS) as $lang) {
            if ($problem = self::check($texts, $recommendations, $lang)) {
                return $problem;
            }
        }
        return null;
    }

    /**
     * Check generated texts.
     *
     * @param string[] $texts Text per block key, tokens still in place.
     * @param recommendation[] $recommendations The recommendations of the blocks.
     * @param string $lang Language of the texts.
     * @return string|null Null when every text is plausible, otherwise the first offending claim as "key:claim".
     */
    public static function check(array $texts, array $recommendations, string $lang): ?string {
        foreach ($recommendations as $rec) {
            if (!isset($texts[$rec->key])) {
                continue;
            }
            $extra = array_diff(self::claims($texts[$rec->key], $lang), self::allowed($rec, $lang));
            if ($extra) {
                return $rec->key . ':' . reset($extra);
            }
        }
        return null;
    }
}
