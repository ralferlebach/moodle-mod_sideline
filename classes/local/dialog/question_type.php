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

/**
 * The four interview formats: configuration, answer validation and effects (P1-08).
 *
 * Effects are a closed, explicit list. An answer changes the commentary only through one of them,
 * and the learner sees which one. Free text never has an effect, is never analysed and is never
 * sent to an AI provider.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_type {
    /** @var string Multiple choice. */
    public const MC = 'mc';
    /** @var string Free text. */
    public const TEXT = 'text';
    /** @var string Slider. */
    public const SLIDER = 'slider';
    /** @var string Semantic differential. */
    public const SEMDIFF = 'semdiff';

    /** @var string[] Allowed types. */
    public const TYPES = [self::MC, self::TEXT, self::SLIDER, self::SEMDIFF];

    /** @var string[] Allowed effects. */
    public const EFFECTS = ['none', 'focus_deadlines', 'small_steps', 'pause_ok'];

    /** @var int Longest free text answer. */
    public const MAX_TEXT = 1000;

    /**
     * Validate and normalise a question configuration.
     *
     * @param string $type Question type.
     * @param array $config Raw configuration.
     * @return array [normalised config, list of error strings]
     */
    public static function normalise_config(string $type, array $config): array {
        $errors = [];
        $clean = static fn($v): string => trim(clean_param((string) $v, PARAM_NOTAGS));
        $effect = static fn($v): string => in_array($v, self::EFFECTS, true) ? $v : 'none';
        switch ($type) {
            case self::MC:
                $options = [];
                foreach ((array) ($config['options'] ?? []) as $option) {
                    $label = $clean($option['label'] ?? '');
                    if ($label !== '') {
                        $options[] = ['label' => $label, 'effect' => $effect($option['effect'] ?? 'none')];
                    }
                }
                if (count($options) < 2 || count($options) > 8) {
                    $errors[] = get_string('err_q_options', 'mod_sideline');
                }
                return [['options' => $options], $errors];
            case self::TEXT:
                $max = min(self::MAX_TEXT, max(10, (int) ($config['maxlength'] ?? 500)));
                return [['maxlength' => $max], $errors];
            case self::SLIDER:
                $min = (int) ($config['min'] ?? 0);
                $max = (int) ($config['max'] ?? 10);
                $step = max(1, (int) ($config['step'] ?? 1));
                if ($max <= $min || ($max - $min) / $step > 100) {
                    $errors[] = get_string('err_q_range', 'mod_sideline');
                }
                return [[
                    'min' => $min, 'max' => $max, 'step' => $step,
                    'minlabel' => $clean($config['minlabel'] ?? ''), 'maxlabel' => $clean($config['maxlabel'] ?? ''),
                    'threshold' => isset($config['threshold']) && $config['threshold'] !== '' ? (float) $config['threshold'] : null,
                    'below' => $effect($config['below'] ?? 'none'), 'atorabove' => $effect($config['atorabove'] ?? 'none'),
                ], $errors];
            case self::SEMDIFF:
                $pairs = [];
                foreach ((array) ($config['pairs'] ?? []) as $pair) {
                    $left = $clean($pair[0] ?? '');
                    $right = $clean($pair[1] ?? '');
                    if ($left !== '' && $right !== '') {
                        $pairs[] = [$left, $right];
                    }
                }
                $scale = (int) ($config['scale'] ?? 7);
                if (!$pairs || count($pairs) > 8) {
                    $errors[] = get_string('err_q_pairs', 'mod_sideline');
                }
                if (!in_array($scale, [5, 7], true)) {
                    $scale = 7;
                }
                return [[
                    'pairs' => $pairs, 'scale' => $scale,
                    'threshold' => isset($config['threshold']) && $config['threshold'] !== '' ? (float) $config['threshold'] : null,
                    'below' => $effect($config['below'] ?? 'none'), 'atorabove' => $effect($config['atorabove'] ?? 'none'),
                ], $errors];
        }
        return [[], [get_string('err_q_type', 'mod_sideline')]];
    }

    /**
     * Validate an answer value against the configuration.
     *
     * @param string $type Question type.
     * @param array $config Normalised configuration.
     * @param mixed $value Raw value from the client.
     * @return string|null JSON encoded stored value, or null when invalid.
     */
    public static function normalise_value(string $type, array $config, mixed $value): ?string {
        switch ($type) {
            case self::MC:
                $index = filter_var($value, FILTER_VALIDATE_INT);
                return ($index !== false && isset($config['options'][$index])) ? json_encode($index) : null;
            case self::TEXT:
                $text = trim(clean_param((string) $value, PARAM_NOTAGS));
                if ($text === '' || mb_strlen($text) > (int) $config['maxlength']) {
                    return null;
                }
                return json_encode($text, JSON_UNESCAPED_UNICODE);
            case self::SLIDER:
                $number = filter_var($value, FILTER_VALIDATE_INT);
                if (
                    $number === false || $number < $config['min'] || $number > $config['max']
                        || ($number - $config['min']) % $config['step'] !== 0
                ) {
                    return null;
                }
                return json_encode($number);
            case self::SEMDIFF:
                $values = is_string($value) ? json_decode($value, true) : $value;
                if (!is_array($values) || count($values) !== count($config['pairs'])) {
                    return null;
                }
                $out = [];
                foreach (array_values($values) as $v) {
                    $v = filter_var($v, FILTER_VALIDATE_INT);
                    if ($v === false || $v < 1 || $v > $config['scale']) {
                        return null;
                    }
                    $out[] = $v;
                }
                return json_encode($out);
        }
        return null;
    }

    /**
     * The effect of a stored answer.
     *
     * @param string $type Question type.
     * @param array $config Normalised configuration.
     * @param string $stored JSON encoded value.
     * @return string One of EFFECTS.
     */
    public static function effect(string $type, array $config, string $stored): string {
        $value = json_decode($stored, true);
        switch ($type) {
            case self::MC:
                return $config['options'][(int) $value]['effect'] ?? 'none';
            case self::SLIDER:
                if ($config['threshold'] === null || !is_numeric($value)) {
                    return 'none';
                }
                return $value < $config['threshold'] ? $config['below'] : $config['atorabove'];
            case self::SEMDIFF:
                if ($config['threshold'] === null || !is_array($value) || !$value) {
                    return 'none';
                }
                $mean = array_sum($value) / count($value);
                return $mean < $config['threshold'] ? $config['below'] : $config['atorabove'];
        }
        // Free text has no effect by design.
        return 'none';
    }
}
