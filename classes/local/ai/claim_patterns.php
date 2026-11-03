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
 * Claim phrase lists of French, Spanish, Italian, Dutch and Portuguese (P-09, P-24).
 *
 * With these lists every language that the language detector knows can be checked semantically, so
 * no AI text reaches a learner in a language in which invented passes, unlockings, deadlines or
 * grades could not be recognised. The lists follow the German ones of claim_checker: negated and
 * compound phrases first, matching order as in the array. They are deliberately broad: a false
 * alarm only costs the AI phrasing, the verified template text is served instead.
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class claim_patterns {
    /** @var array Phrase patterns per language, keyed by the claim constants of claim_checker. */
    public const PATTERNS = [
        'fr' => [
            claim_checker::NORETRY => [
                'plus\s+(?:aucune?\s+)?(?:de\s+)?(?:tentative|essai)s?',
                'aucune?\s+(?:autre\s+)?(?:tentative|essai)',
                'derni(?:er|ère)\s+(?:tentative|essai|chance)',
                'ne\s+(?:peux|pouvez)\s+plus\s+(?:réessayer|recommencer)',
            ],
            claim_checker::DEADLINE => [
                'date\s+limite', 'échéance', 'echeance', 'délai', 'delai', 'expir\w*', 'trop\s+tard', 'en\s+retard',
                'à\s+rendre\s+(?:avant|pour)', 'deadline',
            ],
            claim_checker::TIMESPAN => [
                '\b(?:un|une|deux|trois|quatre|cinq|six|sept|huit|neuf|dix|quelques|plusieurs)\s+'
                    . '(?:minutes?|heures?|jours?|semaines?|mois)\b',
                '\bdemain\b', '\baprès-demain\b', '\bsemaine\s+prochaine\b', '\bmois\s+prochain\b', '\bce\s+soir\b',
                '\b(?:lundi|mardi|mercredi|jeudi|vendredi|samedi|dimanche)\s+prochain\b',
            ],
            claim_checker::FAIL => [
                'échou\w*', 'echou\w*', '\béchec\b', 'pas\s+(?:été\s+)?réussi', 'pas\s+validé', 'note\s+(?:de\s+passage|minimale)',
                'seuil\s+de\s+réussite', 'recalé\w*',
            ],
            claim_checker::COURSECOMPLETE => [
                '(?:terminé|fini|achevé|complété|réussi)\s+(?:tout\s+)?(?:le|ce)\s+cours',
                'cours\s+(?:est\s+)?(?:maintenant\s+)?(?:terminé|fini|achevé|complété)',
            ],
            claim_checker::NOTDONE => [
                'pas\s+encore\s+(?:\p{L}+\s+){0,2}(?:terminé|fini|achevé|complété|fait)',
                'pas\s+(?:terminé|achevé|complété)', 'inachevé', 'incomplet\w*',
            ],
            claim_checker::SUCCESS => ['succès', '\bsucces\b', 'triomph\w*', 'maîtris\w*'],
            claim_checker::LOCKED => [
                'pas\s+encore\s+(?:ouvert|disponible|accessible|débloqué)', 'verrouill\w*', '\bbloqué\w*', '\bfermé\w*',
                's\'ouvr\w*', 'ouvrira', 'sera\s+(?:\p{L}+\s+|\[anon[a-z]*\d*\]\s+){0,3}(?:disponible|ouvert|accessible|débloqué)',
                'prérequis', 'condition\w*\s+(?:préalable|requise)\w*',
            ],
            claim_checker::RETRY => [
                'nouvelle\s+tentative', 'autre\s+(?:tentative|essai|chance)', 'réessa\w*', 'reessa\w*', 'recommenc\w*',
                'deuxième\s+chance', 'encore\s+une\s+(?:fois|tentative|chance)', 'repasser',
            ],
            claim_checker::PASS => ['\bréussi\w*', '\breussi\w*', '\badmis\w*'],
            claim_checker::UNLOCKED => [
                'débloqu\w*', 'déverrouill\w*', 'maintenant\s+(?:ouvert|disponible|accessible)',
                '(?:est|sont)\s+(?:maintenant\s+|désormais\s+)?(?:ouvert|disponible|accessible)s?', 't\'attend',
                'à\s+ta\s+disposition', 'tu\s+peux\s+(?:maintenant|dès\s+maintenant|déjà|commencer)',
                'commence\s+(?:dès\s+)?maintenant',
            ],
            claim_checker::SUBMITTED => ['\bsoumis\w*', '\brendu\w*', '\bremis\w*', '\bdéposé\w*'],
            claim_checker::DONE => [
                '\btu\s+as\b(?:[^.!?]*)\b(?:terminé|fini|achevé|complété)', '\b(?:terminé|achevé|complété)e?s?\b',
            ],
            claim_checker::GRADE => [
                '\bta\s+note\b', '\bnote\s+(?:de|finale|obtenue)\b', '\bnotes\b', '\bpoints?\b', '\bscore\b', 'pourcent\w*', '%',
                'évalu\w*', 'résultat\w*', 'barème',
            ],
            claim_checker::ACTION => ['cliqu\w*', 'bouton', '\blien\b', 'appuie\s+sur'],
        ],
        'es' => [
            claim_checker::NORETRY => [
                'no\s+(?:te\s+)?quedan\s+(?:más\s+)?intentos', 'ningún\s+(?:otro\s+)?intento', 'sin\s+(?:más\s+)?intentos',
                '(?:último|ultimo)\s+intento', 'ya\s+no\s+puedes\s+(?:intentar|repetir)',
            ],
            claim_checker::DEADLINE => [
                'fecha\s+(?:límite|limite|de\s+entrega)', '\bplazo\b', '\bvenc\w*', 'expir\w*', 'caduc\w*', 'demasiado\s+tarde',
                'atrasad\w*', 'deadline',
            ],
            claim_checker::TIMESPAN => [
                '\b(?:un|una|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez|unos\s+pocos|unas\s+pocas|varios|varias)\s+'
                    . '(?:minutos?|horas?|días?|dias?|semanas?|meses|mes)\b',
                '\bmañana\b', 'pasado\s+mañana', '(?:próxima|proxima)\s+semana', '(?:próximo|proximo)\s+mes', 'esta\s+noche',
            ],
            claim_checker::FAIL => [
                'suspend\w*', 'reprob\w*', 'no\s+(?:has\s+)?aprob\w*', 'no\s+(?:has\s+)?superad\w*', 'fallad\w*', 'fracas\w*',
                'nota\s+(?:mínima|minima)', 'nota\s+de\s+aprobado',
            ],
            claim_checker::COURSECOMPLETE => [
                '(?:completado|terminado|finalizado|acabado)\s+(?:todo\s+)?el\s+curso',
                'curso\s+(?:está\s+)?(?:completado|terminado|finalizado|acabado)',
            ],
            claim_checker::NOTDONE => [
                '(?:todavía|aún|aun)\s+no\s+(?:\p{L}+\s+){0,2}(?:completad|terminad|finalizad|hech)\w*',
                'no\s+(?:está\s+)?(?:completad|terminad)\w*', 'incomplet\w*', 'pendiente',
            ],
            claim_checker::SUCCESS => [
                'éxito', '\bexito\w*', 'exitos\w*', 'lo\s+(?:has\s+)?lograd?o', 'lo\s+lograste', 'conseguid\w*',
            ],
            claim_checker::LOCKED => [
                '(?:todavía|aún|aun)\s+no\s+(?:está\s+)?(?:abiert|disponible|accesible|desbloquead)\w*', '\bbloquead\w*',
                '\bcerrad\w*', 'se\s+abr\w*', 'abrirá',
                'estará\s+(?:\p{L}+\s+|\[anon[a-z]*\d*\]\s+){0,3}(?:disponible|abiert\w*|accesible)', 'requisito\w*',
            ],
            claim_checker::RETRY => [
                'otro\s+intento', 'nuevo\s+intento', 'volver\s+a\s+intentar', 'vuelve\s+a\s+intentar', 'reintent\w*',
                'repetir\w*', 'repite\w*', 'segunda\s+oportunidad', 'otra\s+oportunidad', 'de\s+nuevo',
            ],
            claim_checker::PASS => ['\baprobad\w*', '\baprobaste\b', '\bapruebas\b', '\bsuperad\w*', '\bsuperaste\b'],
            claim_checker::UNLOCKED => [
                'desbloquead\w*', 'ya\s+(?:está\s+)?(?:abiert|disponible|accesible)\w*',
                '(?:está|están)\s+(?:ahora\s+|ya\s+)?(?:abiert|disponible|accesible)\w*', 'te\s+espera', 'a\s+tu\s+disposición',
                'puedes\s+(?:ahora|ya|empezar|comenzar)', '(?:empieza|comienza)\s+(?:ya|ahora)',
            ],
            claim_checker::SUBMITTED => ['entregad\w*', 'enviad\w*', 'presentad\w*', '\bentrega\b'],
            claim_checker::DONE => [
                '\bhas\b(?:[^.!?]*)\b(?:completado|terminado|finalizado)', '\b(?:completad|terminad|finalizad)[oa]s?\b',
            ],
            claim_checker::GRADE => [
                '\bnotas?\b', '\bpuntos?\b', 'puntuación', 'puntuacion', 'porcentaje', '%', 'calific\w*', 'evaluad\w*',
                'resultado\w*',
            ],
            claim_checker::ACTION => ['\bclic\w*', 'pulsa\w*', 'botón', '\bboton\b', '\benlace\b', '\blink\b', '\btoca\s+'],
        ],
        'it' => [
            claim_checker::NORETRY => [
                'nessun\s+(?:altro\s+)?tentativo', 'non\s+(?:ti\s+)?(?:restano|rimangono)\s+(?:più\s+)?tentativi',
                'ultimo\s+tentativo', 'non\s+puoi\s+più\s+(?:riprovare|ripetere)',
            ],
            claim_checker::DEADLINE => [
                'scadenz\w*', '\bscad\w*', 'termine\s+(?:ultimo|di\s+consegna)', 'data\s+(?:limite|di\s+consegna)',
                'in\s+ritardo', 'troppo\s+tardi', 'deadline',
            ],
            claim_checker::TIMESPAN => [
                '\b(?:un|uno|una|due|tre|quattro|cinque|sei|sette|otto|nove|dieci|pochi|poche|alcuni|alcune|qualche)\s+'
                    . '(?:minut[oi]|or[ae]|giorn[oi]|settiman[ae]|mes[ei])\b',
                '\bdomani\b', 'dopodomani', 'prossima\s+settimana', 'prossimo\s+mese', 'stasera',
            ],
            claim_checker::FAIL => [
                'bocciat\w*', 'non\s+(?:hai\s+)?superat\w*', 'non\s+(?:sei\s+)?promoss\w*', 'fallit\w*', 'insufficient\w*',
                'voto\s+minimo', 'soglia\s+di\s+superamento',
            ],
            claim_checker::COURSECOMPLETE => [
                '(?:completato|terminato|finito|concluso)\s+(?:tutto\s+)?il\s+corso',
                'corso\s+(?:è\s+)?(?:completato|terminato|finito|concluso)',
            ],
            claim_checker::NOTDONE => [
                'non\s+(?:hai\s+)?ancora\s+(?:\p{L}+\s+){0,2}(?:completat|terminat|finit|conclus)\w*',
                'non\s+(?:è\s+)?(?:completat|terminat)\w*', 'incomplet\w*', 'in\s+sospeso',
            ],
            claim_checker::SUCCESS => ['successo', 'ce\s+l\'hai\s+fatta', 'riuscit\w*'],
            claim_checker::LOCKED => [
                'non\s+(?:è\s+)?ancora\s+(?:apert|disponibil|accessibil|sbloccat)\w*', '\bbloccat\w*', '\bchius\w*', 'si\s+apr\w*',
                'aprirà', 'sarà\s+(?:\p{L}+\s+|\[anon[a-z]*\d*\]\s+){0,3}(?:disponibil|apert|accessibil|sbloccat)\w*',
                'requisit\w*',
            ],
            claim_checker::RETRY => [
                'altro\s+tentativo', 'nuovo\s+tentativo', 'riprov\w*', 'ripet\w*', 'seconda\s+possibilità', 'di\s+nuovo',
                'un\'altra\s+(?:volta|possibilità)',
            ],
            claim_checker::PASS => ['\bsuperat\w*', '\bpromoss\w*', '\bhai\s+passato\b', '\bsuperi\b'],
            claim_checker::UNLOCKED => [
                'sbloccat\w*', 'ora\s+(?:è\s+)?(?:apert|disponibil|accessibil)\w*',
                '(?:è|sono)\s+(?:ora\s+|adesso\s+|già\s+)?(?:apert|disponibil|accessibil)\w*', 'ti\s+aspetta',
                'a\s+tua\s+disposizione', 'puoi\s+(?:ora|adesso|già|iniziare|cominciare)',
                '(?:inizia|comincia)\s+(?:ora|adesso|subito)',
            ],
            claim_checker::SUBMITTED => ['consegnat\w*', 'inviat\w*', 'presentat[oaie]\b', '\bconsegna\b'],
            claim_checker::DONE => [
                '\bhai\b(?:[^.!?]*)\b(?:completato|terminato|finito|concluso)', '\b(?:completat|terminat|conclus)[oaie]\b',
            ],
            claim_checker::GRADE => [
                '\bvot[oi]\b', '\bpunt[oi]\b', 'punteggio', 'percentuale', '%', 'valutat\w*', 'valutazion\w*', 'risultat\w*',
            ],
            claim_checker::ACTION => ['clicc\w*', 'pulsante', 'bottone', '\blink\b', '\bcollegamento\b', '\btocca\s+'],
        ],
        'nl' => [
            claim_checker::NORETRY => [
                'geen\s+(?:pogingen|poging)\s+meer', 'geen\s+(?:andere\s+)?poging', 'laatste\s+(?:poging|kans)',
                'niet\s+meer\s+(?:opnieuw|herkansen)',
            ],
            claim_checker::DEADLINE => [
                'deadline', 'uiterste\s+(?:datum|inleverdatum)', 'inleverdatum', '\btermijn\b', '\bverloopt\b', '\bverlopen\b',
                '\bte\s+laat\b', 'achterstand',
            ],
            claim_checker::TIMESPAN => [
                '\b(?:een|één|twee|drie|vier|vijf|zes|zeven|acht|negen|tien|enkele|paar|meerdere)\s+'
                    . '(?:minuten?|uur|uren|dag|dagen|weken?|maanden?)\b',
                '\bmorgen\b', '\bovermorgen\b',
                'volgende\s+(?:week|maand|maandag|dinsdag|woensdag|donderdag|vrijdag|zaterdag|zondag)', 'vanavond',
            ],
            claim_checker::FAIL => [
                '\bgezakt\b', 'niet\s+gehaald', 'niet\s+geslaagd', 'onvoldoende', '\bmislukt\b', '\bzakken\b', '\bcesuur\b',
            ],
            claim_checker::COURSECOMPLETE => [
                '(?:afgerond|voltooid|afgesloten)\s+(?:de\s+)?(?:hele\s+)?cursus',
                'cursus\s+(?:\p{L}+\s+){0,2}(?:afgerond|voltooid|afgesloten)', 'de\s+(?:hele\s+)?cursus\s+(?:is\s+)?klaar',
            ],
            claim_checker::NOTDONE => [
                'nog\s+niet\s+(?:\p{L}+\s+){0,2}(?:afgerond|voltooid|klaar|gedaan|af)\b', 'niet\s+(?:afgerond|voltooid)',
                'onvolledig', 'onafgemaakt',
            ],
            claim_checker::SUCCESS => ['\bsucces\b', '\bsuccesvol\w*', '\bgelukt\b'],
            claim_checker::LOCKED => [
                'nog\s+niet\s+(?:open|beschikbaar|toegankelijk|vrijgegeven|ontgrendeld)', 'vergrendeld', 'geblokkeerd',
                '\bgesloten\b', 'gaat\s+(?:\p{L}+\s+){0,2}open',
                'wordt\s+(?:\p{L}+\s+|\[anon[a-z]*\d*\]\s+){0,3}(?:beschikbaar|geopend|vrijgegeven|ontgrendeld)',
                'voorwaarde\w*', 'vereiste\w*',
            ],
            claim_checker::RETRY => [
                '(?:nog\s+een|nieuwe|andere)\s+(?:poging|kans)', 'opnieuw\s+(?:proberen|doen|maken)', 'herkans\w*', 'herhal\w*',
                'tweede\s+kans', 'nog\s+eens',
            ],
            claim_checker::PASS => ['\bgeslaagd\b', '\bgehaald\b', '\bslaagt\b'],
            claim_checker::UNLOCKED => [
                'ontgrendeld', 'vrijgegeven', '\bnu\s+(?:open|beschikbaar|toegankelijk)',
                '\b(?:is|zijn)\s+(?:nu\s+|al\s+)?(?:open|beschikbaar|toegankelijk)\b', 'wacht\s+op\s+je', 'tot\s+je\s+beschikking',
                '\bkunt\s+(?:nu|meteen|direct|al)\b', '\b(?:begin|start)\s+(?:nu|meteen|direct)\b',
            ],
            claim_checker::SUBMITTED => ['ingeleverd', 'ingediend', 'verstuurd', 'inlevering'],
            claim_checker::DONE => [
                '\bje\s+hebt\b(?:[^.!?]*)\b(?:afgerond|voltooid|gedaan)', '\b(?:afgerond|voltooid)\b',
            ],
            claim_checker::GRADE => [
                '\bcijfers?\b', '\bpunten\b', '\bscore\b', 'procent', '%', 'beoordel\w*', '\bresultaat\b', '\bresultaten\b',
            ],
            claim_checker::ACTION => ['\bklik\w*', '\bknop\b', '\blink\b', '\btik\s+op\b'],
        ],
        'pt' => [
            claim_checker::NORETRY => [
                'não\s+(?:te\s+)?(?:restam|tem|tens|há)\s+(?:mais\s+)?tentativas', 'nenhuma\s+(?:outra\s+)?tentativa',
                'sem\s+(?:mais\s+)?tentativas', '(?:última|ultima)\s+(?:tentativa|chance|oportunidade)',
            ],
            claim_checker::DEADLINE => [
                '\bprazo\b', 'data\s+(?:limite|de\s+entrega)', '\bvenc\w*', 'expir\w*', 'tarde\s+demais', 'atrasad\w*', 'deadline',
            ],
            claim_checker::TIMESPAN => [
                '\b(?:um|uma|dois|duas|três|tres|quatro|cinco|seis|sete|oito|nove|dez'
                    . '|alguns|algumas|poucos|poucas|vários|várias)\s+'
                    . '(?:minutos?|horas?|dias?|semanas?|mês|meses)\b',
                '\bamanhã\b', '\bamanha\b', 'depois\s+de\s+amanhã', 'próxima\s+semana', 'próximo\s+mês', 'esta\s+noite',
                'hoje\s+à\s+noite',
            ],
            claim_checker::FAIL => [
                'reprovad\w*', 'reprovou', 'não\s+(?:foi\s+)?aprovad\w*', 'não\s+passou', '\bfalh\w*', 'fracass\w*',
                'nota\s+mínima', 'nota\s+de\s+aprovação',
            ],
            claim_checker::COURSECOMPLETE => [
                '(?:concluído|concluido|terminado|finalizado|completado|concluiu|terminou)\s+(?:todo\s+)?o\s+curso',
                'curso\s+(?:está\s+)?(?:concluído|concluido|terminado|finalizado|completo)',
            ],
            claim_checker::NOTDONE => [
                'ainda\s+não\s+(?:\p{L}+\s+){0,2}(?:concluíd|concluid|terminad|finalizad|complet|feit)\w*',
                'não\s+(?:está\s+)?(?:concluíd|concluid|terminad|complet)\w*', 'incomplet\w*', 'pendente',
            ],
            claim_checker::SUCCESS => ['sucesso', 'conseguiu', 'conseguiste', 'mandou\s+bem'],
            claim_checker::LOCKED => [
                'ainda\s+não\s+(?:está\s+)?(?:abert|disponível|disponivel|acessível|acessivel|desbloquead)\w*',
                '\bbloquead\w*', '\bfechad\w*', 'abre\s+(?:em|no|na|amanhã)', 'abrirá',
                'estará\s+(?:\p{L}+\s+|\[anon[a-z]*\d*\]\s+){0,3}(?:disponível|disponivel|abert\w*|acessível)',
                'requisito\w*',
            ],
            claim_checker::RETRY => [
                '(?:outra|nova)\s+(?:tentativa|chance|oportunidade)', 'tentar\s+(?:de\s+)?novo', 'tente\s+(?:de\s+)?novo',
                '\brepetir\b', '\brepita\b', 'refaz\w*', 'segunda\s+chance', 'de\s+novo',
            ],
            claim_checker::PASS => ['\baprovad\w*', '\bpassou\b', '\bpassaste\b', '\baprovou\b'],
            claim_checker::UNLOCKED => [
                'desbloquead\w*', '\bliberad\w*', 'agora\s+(?:está\s+)?(?:abert|disponível|disponivel|acessível)\w*',
                '(?:está|estão)\s+(?:agora\s+|já\s+)?(?:abert|disponível|disponivel|acessível)\w*', '\bte\s+espera\b',
                'à\s+(?:tua|sua)\s+disposição', '\b(?:pode|podes)\s+(?:agora|já|começar|iniciar)\b',
                '\b(?:comece|começa)\s+(?:já|agora)\b',
            ],
            claim_checker::SUBMITTED => ['entregu\w*', 'enviad\w*', 'submetid\w*', '\bentrega\b'],
            claim_checker::DONE => [
                '\b(?:você|voce|tu)\b(?:[^.!?]*)\b(?:concluiu|terminou|completou|finalizou)',
                '\b(?:concluíd|concluid|terminad|completad|finalizad)[oa]s?\b',
            ],
            claim_checker::GRADE => [
                '\bnotas?\b', '\bpontos?\b', 'pontuação', 'pontuacao', 'percentagem', 'porcentagem', '%', 'avaliad\w*',
                'avaliação', 'resultado\w*',
            ],
            claim_checker::ACTION => [
                'cliqu\w*', '\bclica\w*', 'botão', '\bbotao\b', '\blink\b', '\bligação\b', '\btoque\s+(?:em|no|na)\b',
            ],
        ],
    ];
}
