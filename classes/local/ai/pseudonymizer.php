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
 * Builds the minimal, pseudonymised fact set that may leave the site (P-22, P-23, L-13).
 *
 * Allowlist, not blacklist: only the fields assembled here are ever sent. Activity names, dates
 * and the learner's name are replaced by short-lived tokens; exact dates become coarse relative
 * categories. The mapping from token to real value stays in this object, on the server, and is
 * used only after the generated text has been validated.
 *
 * This is pseudonymisation, not anonymisation. Context can still identify a learner indirectly,
 * and the provider's and the core's logging must be assessed separately (P-23, P-26).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class pseudonymizer {
    /** @var string Token for the learner's first name. */
    public const USER_TOKEN = '[anonuser1]';

    /** @var string[] Token to real value. Never serialised, never sent. */
    private array $map = [];

    /** @var array Key of the block to the tokens that its text must contain. */
    private array $required = [];

    /** @var int[] Numbers that may legitimately appear in the generated text. */
    private array $allowednumbers = [];

    /** @var array Facts per block, ready to be embedded into the prompt. */
    private array $blocks = [];

    /** @var int Counter for activity tokens. */
    private int $modcount = 0;

    /** @var int Counter for date tokens. */
    private int $datecount = 0;

    /** @var int Counter for tokens of other people (group mode). */
    private int $peercount = 0;

    /** @var int Counter for group name tokens. */
    private int $groupcount = 0;

    /**
     * Prepare the request.
     *
     * @param recommendation[] $recommendations Ordered recommendations.
     * @param string $firstname First name of the learner, resolved locally only.
     * @param string $dateformat Localised strftime format for dates, used when resolving tokens.
     * @param int $now Current time, for the relative categories.
     * @param string $lang Language of the learner, for custom rule templates.
     */
    public function __construct(array $recommendations, string $firstname, string $dateformat, int $now, string $lang = 'en') {
        $this->map[self::USER_TOKEN] = $firstname;
        $labels = [];
        $dates = [];
        foreach ($recommendations as $rec) {
            $facts = [];
            $required = [];
            foreach (['label', 'nextlabel'] as $field) {
                if (!empty($rec->facts[$field])) {
                    $value = (string) $rec->facts[$field];
                    $labels[$value] = $labels[$value] ?? $this->new_token('anonmod', ++$this->modcount, $value);
                    $facts[$field] = $labels[$value];
                    $required[] = $labels[$value];
                }
            }
            foreach (['deadline', 'opensat'] as $field) {
                if (!empty($rec->facts[$field])) {
                    $time = (int) $rec->facts[$field];
                    $display = userdate($time, $dateformat);
                    $dates[$time] = $dates[$time] ?? $this->new_token('anondate', ++$this->datecount, $display);
                    $facts[$field] = $dates[$time];
                    $facts[$field . '_relative'] = $this->relative_category($time, $now);
                    $required[] = $dates[$time];
                }
            }
            foreach (['done', 'total'] as $field) {
                if (isset($rec->facts[$field])) {
                    $facts['progress_' . $field] = (int) $rec->facts[$field];
                    $this->allowednumbers[] = (int) $rec->facts[$field];
                }
            }
            // Group mode: other people only as tokens, the group name only as a token, counts as numbers.
            if (!empty($rec->facts['names']) && is_array($rec->facts['names'])) {
                $tokens = [];
                foreach ($rec->facts['names'] as $name) {
                    $tokens[] = $this->new_token('anonpeer', ++$this->peercount, (string) $name);
                }
                $facts['people'] = $tokens;
                $required = array_merge($required, $tokens);
            }
            if (!empty($rec->facts['group'])) {
                $token = $this->new_token('anongroup', ++$this->groupcount, (string) $rec->facts['group']);
                $facts['group'] = $token;
                $required[] = $token;
            }
            // Whether a success is a pass or a completion: "passed" may only be said when it is true (P-09).
            if (!empty($rec->facts['outcome'])) {
                $facts['outcome'] = (string) $rec->facts['outcome'];
            }
            if (isset($rec->facts['count'])) {
                $facts['people_count'] = (int) $rec->facts['count'];
                $this->allowednumbers[] = (int) $rec->facts['count'];
            }
            // Custom rules: the teacher's template is the intent, with placeholders turned into tokens.
            if (!empty($rec->facts['templates']) && is_array($rec->facts['templates'])) {
                $template = $rec->facts['templates'][$lang] ?? $rec->facts['templates']['en'] ?? reset($rec->facts['templates']);
                $intent = strtr((string) $template, [
                    '{label}' => $facts['label'] ?? '',
                    '{deadline}' => $facts['deadline'] ?? '',
                    '{done}' => isset($facts['progress_done']) ? (string) $facts['progress_done'] : '',
                    '{total}' => isset($facts['progress_total']) ? (string) $facts['progress_total'] : '',
                ]);
                // A name in a teacher template is still a name: the template text itself is not personal data.
                $facts['intent'] = $intent;
            }
            $this->required[$rec->key] = $required;
            $this->blocks[] = [
                'key' => $rec->key,
                'category' => $this->category_name($rec->category),
                'facts' => $facts,
                'has_action' => $rec->actiontype === recommendation::ACTION_OPEN,
            ];
        }
    }

    /**
     * The facts for the prompt.
     *
     * @return array
     */
    public function blocks(): array {
        return $this->blocks;
    }

    /**
     * The tokens the text of a block must contain.
     *
     * @param string $key Block key.
     * @return string[]
     */
    public function required_tokens(string $key): array {
        return $this->required[$key] ?? [];
    }

    /**
     * Every token that was issued, including the user token.
     *
     * @return string[]
     */
    public function issued_tokens(): array {
        return array_keys($this->map);
    }

    /**
     * Numbers that the generated text may contain.
     *
     * @return int[]
     */
    public function allowed_numbers(): array {
        return $this->allowednumbers;
    }

    /**
     * Replace every token in a validated text by its real value.
     *
     * @param string $text Validated text.
     * @return string
     */
    public function resolve(string $text): string {
        return strtr($text, $this->map);
    }

    /**
     * Register a token.
     *
     * @param string $prefix Token prefix.
     * @param int $index Running number.
     * @param string $value Real value.
     * @return string The token.
     */
    private function new_token(string $prefix, int $index, string $value): string {
        $token = '[' . $prefix . $index . ']';
        $this->map[$token] = $value;
        return $token;
    }

    /**
     * Coarse relative category replacing an exact time.
     *
     * @param int $time Unix time.
     * @param int $now Current time.
     * @return string
     */
    private function relative_category(int $time, int $now): string {
        $delta = $time - $now;
        if ($delta <= 0) {
            return 'already_passed';
        }
        if ($delta <= DAYSECS) {
            return 'within_one_day';
        }
        if ($delta <= 3 * DAYSECS) {
            return 'within_three_days';
        }
        if ($delta <= 14 * DAYSECS) {
            return 'within_two_weeks';
        }
        return 'later';
    }

    /**
     * A readable name for a recommendation category.
     *
     * @param int $category Category constant.
     * @return string
     */
    private function category_name(int $category): string {
        return match ($category) {
            recommendation::CAT_SAFETY => 'deadline_or_access',
            recommendation::CAT_NEXT => 'next_step',
            recommendation::CAT_SUCCESS => 'success',
            recommendation::CAT_ORIENTATION => 'orientation',
            default => 'small_talk',
        };
    }
}
