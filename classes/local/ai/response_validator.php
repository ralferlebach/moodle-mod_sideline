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
 * Validates generated text structurally and plausibly against the facts (P-09, N-03).
 *
 * Any doubt means rejection, and rejection means the caller serves the template text. The checks
 * are deliberately strict: a generated sentence that invents a number, a link or a token is worth
 * less than the reliable template sentence.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class response_validator {
    /** @var int Longest accepted text per block, in characters. */
    public const MAX_LENGTH = 320;

    /** @var string Top-level domains that make a word a bare domain name. */
    private const DOMAINS = 'com|org|net|edu|gov|info|io|ai|app|de|at|ch|eu|uk|fr|es|it|nl|be';

    /** @var string Why the last validation failed, empty after a success. For traces and the debug export. */
    public string $reason = '';

    /**
     * Validate a raw provider response.
     *
     * @param string $raw Raw text returned by the provider.
     * @param string[] $expectedkeys Block keys in the expected order.
     * @param pseudonymizer $request The request, for tokens and allowed numbers.
     * @return string[]|null Text per block key with tokens still in place, or null when rejected.
     */
    public function validate(string $raw, array $expectedkeys, pseudonymizer $request): ?array {
        $texts = $this->blocks($raw, $expectedkeys);
        if ($texts === null) {
            return null;
        }
        foreach ($texts as $key => $text) {
            if (!$this->text_is_acceptable($text, $key, $request)) {
                return null;
            }
        }
        $this->reason = '';
        return $texts;
    }

    /**
     * Validate the response of the translation step (P-24).
     *
     * A translation must keep every block, every token and every number of its source exactly:
     * it may change the wording only. Numbers are compared as a multiset per block, tokens too.
     *
     * @param string $raw Raw text returned by the provider.
     * @param string[] $source Source text per block key, tokens in place, already validated.
     * @return string[]|null Translated text per block key, or null when rejected.
     */
    public function validate_translation(string $raw, array $source): ?array {
        $texts = $this->blocks($raw, array_keys($source));
        if ($texts === null) {
            return null;
        }
        foreach ($texts as $key => $text) {
            if (!$this->basic_text_ok($text)) {
                return null;
            }
            if ($this->tokens_of($text) !== $this->tokens_of($source[$key])) {
                return $this->fail('translation_tokens');
            }
            if ($this->numbers_of($text) !== $this->numbers_of($source[$key])) {
                return $this->fail('translation_numbers');
            }
        }
        $this->reason = '';
        return $texts;
    }

    /**
     * Decode the JSON envelope and check the block keys and their order.
     *
     * @param string $raw Raw response.
     * @param string[] $expectedkeys Expected keys in order.
     * @return string[]|null Trimmed text per key.
     */
    private function blocks(string $raw, array $expectedkeys): ?array {
        $data = json_decode($this->strip_fences($raw), true);
        if (!is_array($data) || !isset($data['blocks']) || !is_array($data['blocks'])) {
            return $this->fail('json');
        }
        $blocks = array_values($data['blocks']);
        if (count($blocks) !== count($expectedkeys)) {
            return $this->fail('block_count');
        }
        $texts = [];
        foreach ($blocks as $index => $block) {
            if (!is_array($block) || ($block['key'] ?? null) !== $expectedkeys[$index] || !is_string($block['text'] ?? null)) {
                return $this->fail('block_key');
            }
            $texts[$block['key']] = trim($block['text']);
        }
        return $texts;
    }

    /**
     * Remove a Markdown code fence that some providers wrap around JSON.
     *
     * @param string $raw Raw response.
     * @return string
     */
    private function strip_fences(string $raw): string {
        $raw = trim($raw);
        if (preg_match('/^\x60\x60\x60[a-zA-Z]*\s*(.*?)\s*\x60\x60\x60$/s', $raw, $matches)) {
            return $matches[1];
        }
        return $raw;
    }

    /**
     * Length, markup, links and control characters.
     *
     * @param string $text Candidate text.
     * @return bool
     */
    private function basic_text_ok(string $text): bool {
        if ($text === '' || mb_strlen($text) > self::MAX_LENGTH) {
            $this->reason = 'length';
            return false;
        }
        // No markup, no links, no control characters, no mail addresses or bare domains.
        if (preg_match('/[<>@]|https?:|:\/\/|www\.|\]\(|[\x00-\x08\x0B\x0C\x0E-\x1F]/i', $text)) {
            $this->reason = 'markup';
            return false;
        }
        if (preg_match('/\b[a-z0-9-]+(?:\.[a-z0-9-]+)*\.(?:' . self::DOMAINS . ')\b/i', $text)) {
            $this->reason = 'markup';
            return false;
        }
        // Digits of other scripts would slip past the number check, which compares ASCII digits.
        if (preg_match('/(*UCP)\d/u', (string) preg_replace('/[0-9]/', '', $text))) {
            $this->reason = 'number';
            return false;
        }
        return true;
    }

    /**
     * Check a single text.
     *
     * @param string $text Candidate text.
     * @param string $key Block key.
     * @param pseudonymizer $request The request.
     * @return bool
     */
    private function text_is_acceptable(string $text, string $key, pseudonymizer $request): bool {
        if (!$this->basic_text_ok($text)) {
            return false;
        }

        // Every token-like fragment must be a token of this very block (or the learner), and every
        // required token must be present. A token of another block would attach its facts here.
        $own = array_merge([pseudonymizer::USER_TOKEN], $request->required_tokens($key));
        $issued = $request->issued_tokens();
        foreach (array_keys($this->tokens_of($text)) as $token) {
            if (!in_array($token, $issued, true)) {
                $this->reason = 'token_unknown';
                return false;
            }
            if (!in_array($token, $own, true)) {
                $this->reason = 'token_foreign';
                return false;
            }
        }
        foreach ($request->required_tokens($key) as $required) {
            if (!str_contains($text, $required)) {
                $this->reason = 'token_missing';
                return false;
            }
        }

        // Numbers: only those that are part of the facts. Dates, grades and counts cannot be invented.
        foreach (array_keys($this->numbers_of($text)) as $number) {
            if (!in_array((int) $number, $request->allowed_numbers(), true)) {
                $this->reason = 'number';
                return false;
            }
        }
        return true;
    }

    /**
     * Tokens of a text with their counts, sorted.
     *
     * @param string $text Text.
     * @return int[] Token to count.
     */
    private function tokens_of(string $text): array {
        preg_match_all('/\[anon[a-z]*\d*\]/i', $text, $found);
        $counts = array_count_values($found[0]);
        ksort($counts);
        return $counts;
    }

    /**
     * Numbers outside tokens with their counts, sorted.
     *
     * @param string $text Text.
     * @return int[] Number to count.
     */
    private function numbers_of(string $text): array {
        preg_match_all('/\d+/', (string) preg_replace('/\[anon[a-z]*\d*\]/i', '', $text), $found);
        $counts = array_count_values(array_map(static fn($n) => (string) (int) $n, $found[0]));
        ksort($counts);
        return $counts;
    }

    /**
     * Record a failure reason.
     *
     * @param string $reason Reason code.
     * @return null
     */
    private function fail(string $reason): ?array {
        $this->reason = $reason;
        return null;
    }
}
