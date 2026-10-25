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

    /**
     * Validate a raw provider response.
     *
     * @param string $raw Raw text returned by the provider.
     * @param string[] $expectedkeys Block keys in the expected order.
     * @param pseudonymizer $request The request, for tokens and allowed numbers.
     * @return string[]|null Text per block key with tokens still in place, or null when rejected.
     */
    public function validate(string $raw, array $expectedkeys, pseudonymizer $request): ?array {
        $data = json_decode($this->strip_fences($raw), true);
        if (!is_array($data) || !isset($data['blocks']) || !is_array($data['blocks'])) {
            return null;
        }
        $blocks = array_values($data['blocks']);
        if (count($blocks) !== count($expectedkeys)) {
            return null;
        }

        $texts = [];
        foreach ($blocks as $index => $block) {
            if (!is_array($block) || ($block['key'] ?? null) !== $expectedkeys[$index] || !is_string($block['text'] ?? null)) {
                return null;
            }
            $text = trim($block['text']);
            if (!$this->text_is_acceptable($text, $block['key'], $request)) {
                return null;
            }
            $texts[$block['key']] = $text;
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
     * Check a single text.
     *
     * @param string $text Candidate text.
     * @param string $key Block key.
     * @param pseudonymizer $request The request.
     * @return bool
     */
    private function text_is_acceptable(string $text, string $key, pseudonymizer $request): bool {
        if ($text === '' || mb_strlen($text) > self::MAX_LENGTH) {
            return false;
        }
        // No markup, no links, no control characters.
        if (preg_match('/[<>]|https?:|:\/\/|www\.|\]\(|[\x00-\x08\x0B\x0C\x0E-\x1F]/i', $text)) {
            return false;
        }

        // Every token-like fragment must be a token that was issued, and every required token must be present.
        preg_match_all('/\[anon[a-z]*\d*\]/i', $text, $found);
        $issued = $request->issued_tokens();
        foreach ($found[0] as $token) {
            if (!in_array($token, $issued, true)) {
                return false;
            }
        }
        foreach ($request->required_tokens($key) as $required) {
            if (!str_contains($text, $required)) {
                return false;
            }
        }

        // Numbers: only those that are part of the facts. Dates, grades and counts cannot be invented.
        $stripped = preg_replace('/\[anon[a-z]*\d*\]/i', '', $text);
        preg_match_all('/\d+/', $stripped, $numbers);
        foreach ($numbers[0] as $number) {
            if (!in_array((int) $number, $request->allowed_numbers(), true)) {
                return false;
            }
        }
        return true;
    }
}
