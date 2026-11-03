# Sprachmatrix (L-07, P-07 bis P-09, P-24; Issues #13, #17)

Stand 0.4.1.

## 1. Was je Sprache gilt

| Sprache | Sprachpaket des Plugins | Vorlagentexte | KI-Formulierung | Prüfung erfundener Fakten | Kennzeichnung im Panel |
|---|---|---|---|---|---|
| Englisch (`en`) | ja | ja | ja, direkt | ja | – |
| Deutsch (`de`, `de_du`) | ja | ja, wenn das Moodle-Sprachpaket `de` installiert ist | ja, direkt | ja | – |
| Französisch (`fr`) | nein | Englisch (Fallback) | ja, direkt auf Französisch | ja | „Diese Nachricht ist in deiner Sprache noch nicht verfügbar.“ bei Vorlagen |
| Spanisch, Italienisch, Niederländisch, Portugiesisch (`es`, `it`, `nl`, `pt`, `pt_br`) | nein | Englisch (Fallback) | ja, direkt | ja | wie Französisch |
| alle anderen (zum Beispiel `pl`, `zh_cn`) | nein | Englisch (Fallback) | **nein** | – | Fallback-Hinweis |

- **Direkt:** Die KI schreibt in der Sprache der lernenden Person. Der Text wird in dieser Sprache auf erfundene Bestehen, Freischaltungen, Fristen, Noten, Wiederholungen und Kursabschlüsse geprüft.
- **Übersetzung:** Antwortet die KI in einer anderen unterstützten Sprache, folgt ein eigener Übersetzungsschritt. Dessen Ergebnis wird erneut auf Tokens, Zahlen, Sprache und Behauptungen in der Zielsprache geprüft. Das Panel zeigt dann „Automatisch übersetzt und geprüft.“ (Quelle `translated`).
- **Nie als übersetzt gekennzeichnet:** Vorlagentexte, auch im Fallback. Hier zeigt das Panel nur, dass der Text nicht in der eigenen Sprache ist (`langfallback`).
- **Kein Erfolg bei Fehlern:** Eine gescheiterte oder abgelehnte Übersetzung wird nie als KI-Text gespeichert oder gezeigt. Dann gilt der Vorlagentext.
- Eine Sprache ohne Prüfregeln bekommt nie KI-Text (`ai_gateway::language_supported`).

## 2. Konfiguration

- **Sprache der Person:** Moodle-Profilsprache bzw. Sitzungssprache (`current_language()`). Ein Wechsel zur Laufzeit wirkt sofort: Sprache und Sprachpaketrevision gehen in den Cache-Fingerabdruck ein.
- **Sprachpakete:** Die Vorlagen kommen aus den Sprachdateien des Plugins. Lokale Anpassungen (`tool_customlang`) gelten sofort.
- **Fallback:** Die Sprache eines Vorlagentexts ist die Sprache, in der es Texte dieses Plugins gibt: die Sprache selbst, sonst ihre Elternsprache (etwa `de` für `de_du`), sonst Englisch (`message_composer::text_language`). Texte gibt es, wenn das Plugin sie mitbringt (`lang/de`), wenn das installierte Sprachpaket sie enthält oder wenn sie lokal angepasst sind (`tool_customlang`). Ein installiertes Sprachpaket allein genügt nicht: Pakete vom Moodle-Sprachserver enthalten nur dort registrierte Plugins. Bis 0.4.0 galt ein solcher englischer Text als Text in der Paketsprache, und der Hinweis fehlte.
- **Hinweis im Panel:** erscheint, wenn die Sprachfamilie des Texts von der Sprache der Person abweicht. `de` für `de_du` bekommt keinen Hinweis.
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
| installiertes Paket ohne Texte des Plugins: englischer Vorlagentext, als Englisch gekennzeichnet; lokale Anpassung macht die Sprache zur Textsprache | `language_matrix_test::test_an_installed_pack_without_texts_of_the_plugin_is_labelled_as_fallback` |
| Kindsprache nimmt die Texte der Elternsprache, ohne Hinweis | `language_matrix_test::test_a_child_language_takes_the_texts_of_its_parent_without_a_fallback_label` |
| **Im Browser:** Deutsch (Texte des Plugins, KI-Text direkt auf Deutsch, kein Hinweis) | Behat `languages.feature` |
| **Im Browser:** Französisch (englische KI-Antwort, Übersetzungsschritt, Hinweis „übersetzt und geprüft“, zwei Prompts) | Behat `languages.feature` |
| **Im Browser:** Polnisch (Paket ohne Plugin-Texte, keine Prüfregeln: englische Vorlage mit Hinweis, kein Prompt) | Behat `languages.feature` |
| **Im Browser:** Rechts-nach-links (`he`) | Playwright `a11y.spec.js` |

Die CI-Sites haben nur das englische Sprachpaket. Behat und Playwright legen darum für die Sprachtests ein minimales Paket an (nur `langconfig.php`, Schritt „the language pack … is installed for testing“, `tests/playwright/seed.php`). Das entspricht einem Paket vom Sprachserver ohne dieses Plugin. Der simulierte KI-Anbieter schreibt Deutsch direkt und antwortet für Französisch absichtlich auf Englisch, damit der Übersetzungsweg durchlaufen wird.
