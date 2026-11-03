# Security- und Privacy-Review 0.4.1 (Issues #3, #4, #12, #14)

Stand 03.11.2026.

## 1. Wer und wie

- **Prüfender:** ein eigenständiger KI-Agent (Claude), getrennt von der Entwicklung gestartet, ohne die Schlussfolgerungen der Entwicklung. Er bekam nur die Anforderungen und den Code. Er hatte nur Lesezugriff, hat nichts am Plugin geändert und jede Aussage über Moodle gegen den Kern-Quelltext 5.3 geprüft.
- **Grenze:** Das ist ein unabhängiges technisches Review, keine Abnahme durch eine Person mit Verantwortung für Sicherheit oder Datenschutz. Eine solche Abnahme ist eine fachliche Entscheidung außerhalb der CI (siehe `docs/privacy/DATENSCHUTZ.md`, D1 bis D5).
- **Prüfstand:** Arbeitsstand 0.4.1 vor der Umsetzung dieser Befunde.
- **Originalbericht (Englisch):** `review_0.4.1_report_en.md`, mit den Nachweisen für alles, was hält.

Geprüfte Anforderungen:

- **R1 Widerruf:** In keiner Verschränkung wird nach einem Widerruf etwas aus den Daten der Person ausgeliefert oder gespeichert. Ein Sperr-Timeout führt nie zu persönlichem Text.
- **R2 Freitext an die KI:** keine Namen, Kennungen oder Prompt-Injection; im Zweifel wird nichts gesendet. Lernerdaten nur pseudonymisiert, Antworten werden geprüft.
- **R3 Gruppenaussagen:** mindestens drei Personen, Bänder statt Zahlen, stabil innerhalb eines Tages; Namen nur mit aktueller Zustimmung.
- **R4 Einstiegspunkte:** Login, Kontext, Recht und Sesskey; nichts von Lernenden erreicht Lehrende.
- **R5 Privacy-API:** jede personenbezogene Tabelle wird exportiert und gelöscht.

## 2. Ergebnis vor der Nacharbeit

| Anf. | Urteil | Kern |
|---|---|---|
| R1 | teilweise | Das Protokoll aus Cache und Auslieferung hält in jeder Verschränkung und bei jedem Sperr-Timeout. Traces, Regeltreffer und die Protokolle des KI-Kerns blieben aber nach einem Widerruf stehen. |
| R2 | Mangel | Regelschlüssel, Persona-Stile und weitere Freitextformen gingen ungeprüft an den Anbieter. |
| R3 | teilweise | Mindestgröße, Bänder und Namenszustimmung halten. Die Tagesstabilität ließ sich unterlaufen, und eine Prüfung hing an einer Sperre. |
| R4 | teilweise | Alle Seiten und Funktionen prüfen korrekt. Der KI-Nutzungsbericht des Kerns zeigte Lehrenden aber, wer den Coach nutzt. |
| R5 | teilweise | Die neun Lerntabellen sind abgedeckt, Autor-IDs in drei Konfigurationstabellen nicht. |

Es gab weder kritische noch hohe Befunde, keine SQL-Injection, kein XSS und keinen fehlerhaften `send_file()`-Aufruf.

## 3. Befunde und Umsetzung

| Nr. | Schwere | Befund | Umsetzung in 0.4.1 | Test |
|---|---|---|---|---|
| F1 | mittel | Schlüssel eigener Regeln (`custom_…`, von Lehrenden frei benannt) gingen im Prompt mit. Eine Wiederherstellung übernahm Regeldefinitionen ungeprüft. | Der Anbieter sieht nur neutrale Blockkennungen `b1`, `b2` …, auch im Übersetzungsschritt; lokal wird zurückgemappt (`pseudonymizer::aliases()`, `by_key()`). Die Wiederherstellung prüft jede Regel mit `rule_schema::validate()` und verwirft ungültige. | `prompt_guard_test::test_the_provider_sees_block_ids_never_the_keys_of_rules` |
| F2 | mittel | Der Kern-Bericht „KI-Nutzung“ des Kurses zeigt Lehrenden jeden KI-Aufruf mit Namen, Aktivität und Zeit. Daraus lässt sich die Zustimmung ableiten (P-40). | KI-Aufrufe werden im **Nutzerkontext** der lernenden Person registriert, nicht in Kurs oder Aktivität (`core_text_generator::generate()`). Ob KI in der Aktivität erlaubt ist, entscheidet weiterhin deren Kontext. | `ai_integration_test::test_translation_step_through_core_…` (Kontext der Registrierung) |
| F3 | mittel | Traces mit dem ausgelieferten Text und Regeltreffer blieben nach einem Widerruf stehen. Sie wurden außerdem erst nach der letzten Prüfung geschrieben. | Ein Widerruf entfernt Cache, Traces und Regeltreffer (`consent_manager::forget_generated()`). Die Erzeugung prüft erst nach dem **letzten** Schreibvorgang und räumt bei einem Widerruf alles wieder ab. | `delivery_atomicity_test::test_a_withdrawal_removes_traces_…`, `::test_a_withdrawal_during_generation_leaves_no_trace_of_the_text` |
| F4 | mittel | Prompt und Antwort im KI-Kern blieben nach einem Widerruf erhalten. | Ein Widerruf leert sie über die Privacy-API des Kerns im Nutzerkontext (alle KI-Aufrufe des Plugins für diese Person, denn der Kern unterscheidet Instanzen nicht). | `ai_integration_test` (nach dem Widerruf kein Prompt und keine Antwort mit Tokens mehr) |
| F5 | mittel | Persona-Stile wurden nicht auf Namen geprüft. | Site-Personas und Vorschaustile werden gegen die Namen der lernenden Person und aller Personen der beteiligten Kurse geprüft. Die eingebauten Stile sind eigener Text des Plugins. | `prompt_guard_test::test_a_site_persona_naming_somebody_of_the_course_is_never_sent` |
| F6 | mittel | Die Prüfung übersah „November 3rd“, „3 Nov“, „3. Nov.“, „discord.gg“, „t.co/…“, „anna(at)…(dot)de“, „Disregard your earlier guidance“ und Injection auf Spanisch und Italienisch. | Monatsabkürzungen, Ordinalzahlen, „by the 21st“; jeder Hostname mit Top-Level-Domain; verschleierte E-Mail-Adressen; mehr Verben und Ziele bei Injection; Muster für es, it, nl, pt und Rollenwechsel in diesen Sprachen. | `prompt_guard_test::unsafe_provider` (14 neue Fälle) |
| F7 | niedrig | Eine erneut abgehakte Erledigung bekommt von Moodle einen neuen Zeitstempel. Dadurch fiel die Person aus der Zählung vor Mitternacht, und die Aussage änderte sich im Lauf des Tages. | Tagesschnappschuss: Wer am Tag einmal gezählt wurde, bleibt für den Tag gezählt (Moodle-Cache `groupsnapshot`, nur Nutzer-IDs). Wer nicht mehr teilt oder das Ereignis nicht mehr hat, fällt sofort heraus. | `group_privacy_test::test_a_completion_stamped_again_today_does_not_change_the_statement_of_the_day` |
| F8 | niedrig | Kam die Freigabesperre nicht rechtzeitig, prüfte die empfangende Anfrage nach dem Schreiben nicht erneut. | Nach dem letzten Schreibvorgang werden die Personen der Aussage erneut geprüft, unabhängig von der Sperre. Fällt die Prüfung negativ aus, werden Cache und Trace verworfen. | `group_privacy_test::test_a_change_during_ai_generation_delivers_no_statement_of_the_old_circle` (Freigabeende während der Erzeugung); den Sperr-Timeout selbst prüft kein eigener Test |
| F9 | niedrig | `usermodified` in `sideline_source` und `sideline_sourcecat` sowie `usermodified`/`approvedby` in `sideline_persona` waren in der Privacy-API nicht erfasst. | Die Felder sind beschrieben, werden exportiert und beim Löschen auf 0 gesetzt. Eine Kursquelle ohne Lehrperson wird dann erst nach erneuter Bestätigung wieder ausgewertet. | `provider_test::test_teachers_who_authorised_sources_and_authors_of_personas_are_covered` |
| F10 | niedrig | Geprüft wurden nur Namen des eigenen Kurses, nicht die der Kurse im Kursverbund. | Geprüft werden der eigene Kurs, die weiteren Kurse und bei Kursbereichen die Kurse der lernenden Person. | `prompt_guard_test::test_names_of_further_courses_are_checked_as_well` |
| F11 | niedrig | KI-Antworten wurden nicht auf Namen geprüft. | Jede Antwort wird vor der Auslieferung geprüft, nach einer Übersetzung erneut. Ein Name der lernenden Person oder einer Person der beteiligten Kurse führt zur Vorlage (Status `invalid:name`). | `prompt_guard_test::test_an_answer_with_a_name_of_the_course_is_never_shown` |
| F12 | niedrig | Bei genau drei anderen Mitgliedern beschrieb „einige“ jedes einzelne. | Eine anonyme Aussage, die alle anderen Mitglieder einer Gruppe umfassen würde, entfällt. | `group_privacy_test::test_a_statement_never_covers_every_other_member_of_the_group` |
| – | – | `rules.php`: TypeError bei der Vorschau einer ungültigen Regel | Die Vorschau läuft nur mit einer gültigen Definition. | – |

## 4. Nicht bewiesen, bewertet und dokumentiert

- **Großschreibung und Unicode:** Der Namensabgleich vergleicht `LOWER()` der Datenbank mit `mb_strtolower()`. Bei PostgreSQL mit C-Locale oder unterschiedlich normalisiertem Unicode können Nicht-ASCII-Namen durchrutschen. Die Moodle-Voraussetzung ist eine UTF-8-Datenbank. Ein Restrisiko für Sonderkonfigurationen bleibt (`DATENSCHUTZ.md`).
- **Namensteile:** Nur ganze Vor- oder Nachnamen werden erkannt. „Weizsäcker“ im Text gegen den Nachnamen „von Weizsäcker“ wird nicht erkannt.
- **Verkettbare Nutzerkennung:** Die Kern-Anbieter senden `sha256(siteidentifier + Nutzer-ID)`. Das ist Kernverhalten und eine Entscheidung D5.
- **Reihenfolge:** Eine Antwort, die ihre letzte Prüfung unmittelbar vor dem Widerruf bestanden hat, kann den Browser nach der Antwort auf den Widerruf erreichen. Das ist eine akzeptierte Linearisierung: Gespeichert bleibt nichts.
- **Snapshot und Cache-Leerung:** Wird der Moodle-Cache mitten am Tag geleert, wird neu gezählt. Eine erneut abgehakte Erledigung kann dann aus der Zählung fallen. Das passiert selten und gibt nicht mehr preis als vor 0.4.1.
