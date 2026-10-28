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
 * Checks in which language a generated text is written (P-24, L-07).
 *
 * Function-word profiles, no external service and no model: short coaching texts consist mostly
 * of articles, pronouns, prepositions and auxiliaries, and these differ clearly between the
 * supported languages. A word that occurs in more than one profile is ignored, so a profile can
 * only ever count words that are specific to its language. A text whose language cannot be
 * determined with a clear margin counts as "unknown", which the gateway treats as a failure.
 *
 * Languages without a profile cannot be checked. For them AI is not used and the localised
 * template text is served ("unknown language" in P-24).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class language_detector {
    /** @var string Result when no language can be determined with a clear margin. */
    public const UNKNOWN = '';

    /** @var array Function words per language family. Shared words are removed in profiles(). */
    private const WORDS = [
        'en' => 'the and you your yours is are have has had to of on with for it its this that these those not yet '
            . 'will be been can could what when where which who next now again here there good great nice work '
            . 'well keep look ready back let let\'s just still already one more another some any about after '
            . 'before from into through once whenever up do does did don\'t isn\'t aren\'t you\'re you\'ve it\'s '
            . 'my our their they we he she him her them very really recently',
        'de' => 'der die das den dem des und du dein deine deinen deinem deiner dich dir ist hast bist nicht noch '
            . 'mit für auf zu von sich ein eine einen einem einer es wie wann jetzt schon sehr gut nächste '
            . 'nächsten weiter mal kannst wird sind aus bei nach über um zum zur sie wir ihr auch aber oder '
            . 'dann hier immer wieder dass weil wenn kein keine keinen bereits etwas diese dieser dieses '
            . 'damit davor danach heute schön lass uns am im bis haben hat kürzlich sobald',
        'fr' => 'le les et tu ton ta tes toi te vous votre vos est pas une des du pour avec sur dans ce cette '
            . 'ces qui bien très encore déjà maintenant prochaine prochain suivant suivante fait as peux sont '
            . 'au aux mais ou donc ici toujours aussi rien plus moins ton ta voici voilà bravo',
        'es' => 'el los las y tú tus usted está estás eres pero muy para por del al lo su sus este esta estos '
            . 'estas ya ahora siguiente bien mucho todavía aún aquí también sin sobre hasta desde cuando '
            . 'puedes tienes hecho buen buena',
        'it' => 'il gli e è sei hai non della dello delle degli nel nella sono molto ancora già adesso ora '
            . 'prossimo prossima bene tuo tua tuoi tue ti questo questa anche ma oppure qui sempre puoi fatto '
            . 'brava bravo',
        'nl' => 'het een je jij jouw jullie niet nog met voor op aan van dat wat goed heel nu volgende weer ook '
            . 'maar kun kunt hebt heb bent zijn er naar bij om dit deze al gedaan klaar',
        'pt' => 'o os você voce seu sua seus suas está não nao um com muito ainda já agora próximo próxima bem '
            . 'foi são isso esse essa do da dos das nos nas pelo pela também aqui sempre pode feito',
    ];

    /** @var array|null Profiles: language family to word set, shared words removed. */
    private static ?array $profiles = null;

    /**
     * The language family of a Moodle language code: "de_du" is "de", "pt_br" is "pt".
     *
     * @param string $lang Moodle language code.
     * @return string
     */
    public static function base_language(string $lang): string {
        // PARAM_LANG is empty for languages without a pack; the code itself is still a valid key.
        $code = clean_param($lang, PARAM_LANG) ?: $lang;
        return preg_replace('/[^a-z]/', '', strtolower(explode('_', $code)[0]));
    }

    /**
     * Whether texts in this language can be checked.
     *
     * @param string $lang Moodle language code or family.
     * @return bool
     */
    public static function supports(string $lang): bool {
        return isset(self::profiles()[self::base_language($lang)]);
    }

    /**
     * Detect the language family of a text.
     *
     * @param string $text Text, tokens such as [anonmod1] are ignored.
     * @return string Language family, or UNKNOWN.
     */
    public static function detect(string $text): string {
        $text = preg_replace('/\[anon[a-z]*\d*\]/i', ' ', $text);
        $text = \core_text::strtolower((string) $text);
        // Typographic apostrophes as plain ones, everything except letters and apostrophes as spaces.
        $text = str_replace(["\u{2019}", "\u{2018}"], "'", $text);
        $words = preg_split('/[^\p{L}\']+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $scores = [];
        foreach (self::profiles() as $family => $profile) {
            $scores[$family] = 0;
            foreach ($words as $word) {
                $word = trim($word, "'");
                if (isset($profile[$word])) {
                    $scores[$family]++;
                }
            }
        }
        arsort($scores);
        $best = array_key_first($scores);
        $values = array_values($scores);
        if ($values[0] < 1 || (isset($values[1]) && $values[0] <= $values[1])) {
            return self::UNKNOWN;
        }
        return $best;
    }

    /**
     * Profiles with every word that belongs to more than one language removed.
     *
     * @return array Family to word set.
     */
    private static function profiles(): array {
        if (self::$profiles === null) {
            $sets = [];
            $count = [];
            foreach (self::WORDS as $family => $words) {
                $sets[$family] = array_flip(array_unique(preg_split('/\s+/u', trim($words))));
                foreach (array_keys($sets[$family]) as $word) {
                    $count[$word] = ($count[$word] ?? 0) + 1;
                }
            }
            foreach ($sets as $family => $set) {
                $sets[$family] = array_filter($set, static fn($unused, $word) => $count[$word] === 1, ARRAY_FILTER_USE_BOTH);
            }
            self::$profiles = $sets;
        }
        return self::$profiles;
    }
}
