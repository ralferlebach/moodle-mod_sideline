# Sprachmatrix (L-07, P-07 bis P-09, P-24; Issues #13, #17)

Stand 0.4.0.

## 1. Was je Sprache gilt

| Sprache | Sprachpaket des Plugins | Vorlagentexte | KI-Formulierung | Prüfung erfundener Fakten | Kennzeichnung im Panel |
|---|---|---|---|---|---|
| Englisch (`en`) | ja | ja | ja, direkt | ja | – |
| Deutsch (`de`, `de_du`) | ja | ja, wenn das Moodle-Sprachpaket `de` installiert ist | ja, direkt | ja | – |
| Französisch (`fr`) | nein | Englisch (Fallback) | ja, direkt auf Französisch | ja (neu in 0.4.0) | „Diese Nachricht ist in deiner Sprache noch nicht verfügbar.“ bei Vorlagen |
| Spanisch, Italienisch, Niederländisch, Portugiesisch (`es`, `it`, `nl`, `pt`, `pt_br`) | nein | Englisch (Fallback) | ja, direkt | ja (neu in 0.4.0) | wie Französisch |
| alle anderen (zum Beispiel `pl`, `zh_cn`) | nein | Englisch (Fallback) | **nein** | – | Fallback-Hinweis |

- **Direkt:** Die KI schreibt in der Sprache der lernenden Person. Der Text wird in dieser Sprache auf erfundene Bestehen, Freischaltungen, Fristen, Noten, Wiederholungen und Kursabschlüsse geprüft.
- **Übersetzung:** Antwortet die KI in einer anderen unterstützten Sprache, folgt ein eigener Übersetzungsschritt. Dessen Ergebnis wird erneut auf Tokens, Zahlen, Sprache und Behauptungen in der Zielsprache geprüft. Das Panel zeigt dann „Automatisch übersetzt und geprüft.“ (Quelle `translated`).
- **Nie als übersetzt gekennzeichnet:** Vorlagentexte, auch im Fallback. Hier zeigt das Panel nur, dass der Text nicht in der eigenen Sprache ist (`langfallback`).
- **Kein Erfolg bei Fehlern:** Eine gescheiterte oder abgelehnte Übersetzung wird nie als KI-Text gespeichert oder gezeigt. Dann gilt der Vorlagentext.
- Eine Sprache ohne Prüfregeln bekommt nie KI-Text (`ai_gateway::language_supported`).

## 2. Konfiguration

- **Sprache der Person:** Moodle-Profilsprache bzw. Sitzungssprache (`current_language()`). Ein Wechsel zur Laufzeit wirkt sofort: Sprache und Sprachpaketrevision gehen in den Cache-Fingerabdruck ein.
- **Sprachpakete:** Die Vorlagen kommen aus den Sprachdateien des Plugins. Lokale Anpassungen (`tool_customlang`) gelten sofort.
- **Fallback:** Ohne Sprachpaket gilt Englisch (Moodle-Standard).
- **Personas:** Eröffnungssätze je Sprache in der Persona-Definition, sonst Englisch. Interviewfragen werden in der Sprache gezeigt, in der die Lehrperson sie angelegt hat (Moodle-Filter `multilang` möglich).

## 3. Nachweise

| Prüfung | Test |
|---|---|
| erfundene Fakten ohne Zahlen in fr, es, it, nl, pt (Bestehen, Freischaltung, Kursabschluss, Wiederholung, Note, Frist, Nichtbestehen) | `tests/local/ai/language_matrix_test.php::test_invented_facts_are_recognised_in_every_language` (15 Fälle) |
| gemischte Sprachen in einer Antwort | `language_matrix_test::test_a_mixed_language_answer_is_checked_in_every_language` |
| gescheiterte Übersetzung nicht gespeichert, nicht als KI | `language_matrix_test::test_a_failed_translation_is_never_stored_or_shown_as_ai` |
| Fallback-Sprache gekennzeichnet, nie als übersetzt | `language_matrix_test::test_a_language_without_a_pack_is_labelled_as_fallback_never_as_translated`, Jest (React, AMD) |
| Sprachwechsel zur Laufzeit (de, en) | `language_matrix_test::test_switching_the_language_at_runtime_changes_text_and_cache` |
| Französisch direkt, englische Antwort übersetzt und erneut geprüft | `ai_language_test`, `ai_integration_test` (über den echten Moodle-KI-Adapter) |
| Deutsch und Englisch, Prompt- und Antworttests | `ai_language_test`, `ai_pipeline_test` |

Behat und Playwright laufen auf Englisch: Die CI-Sites haben nur das englische Sprachpaket.
