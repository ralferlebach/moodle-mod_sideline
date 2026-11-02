# Nachweise 0.4.0 zu den Issues #1 bis #18

Stand: 03.11.2026. Grundlage: Re-Audit vom 02.11.2026 auf Commit `092a7c1` (0.3.2).

Dieses Dokument ordnet jedem Akzeptanzkriterium (AK) und jedem DoD-Punkt die Umsetzung (Fundstelle) und den ausgeführten Nachweis zu. Es ist zum Übertragen in die Issues gedacht. Die Issues verwaltet ausschließlich das Audit; aus dieser Sitzung heraus wurde kein Issue geändert.

**Wo die Läufe herkommen:** Alle hier genannten Läufe liefen lokal mit moodle-plugin-ci 4.5.11, mit denselben Schritten und Optionen wie in `.github/workflows/moodle-plugin-ci-main.yml` (`tools/ci-local/`), und zwar auf dem entpackten Auslieferungs-ZIP `mod_sideline-0.4.0.zip`. GitHub-Run-IDs auf dem Commit von 0.4.0 entstehen erst nach dem Push. Sie sind dann je Issue nachzutragen (Spalte „GitHub“).

Legende: ✔ erfüllt und ausgeführt · ◐ technisch erfüllt, fachliche Entscheidung oder GitHub-Lauf steht aus

## Gesamtergebnis der Abnahmeläufe

Alle Läufe vom 02./03.11.2026 im Container, auf dem entpackten ZIP:

| Prüfung | Ergebnis |
|---|---|
| CI-Matrix (moodle-plugin-ci 4.5.11, Schritte wie `moodle-plugin-ci-main.yml`): 4.5/8.2/pgsql, 4.5/8.3/mariadb, 5.0/8.2/pgsql, 5.0/8.3/mariadb, 5.1/8.3/pgsql, 5.2/8.3/mariadb, 5.3/8.3/pgsql, 5.3/8.3/mariadb | **8/8 Zellen grün**, je 14 Schritte: install, phplint, phpcpd, phpmd, codechecker (`--max-warnings 0`), phpdoc (`--max-warnings 0`), validate, savepoints, mustache, grunt (`--max-lint-warnings 0`), PHPUnit, Adapter-Suites, Races, Behat |
| PHPUnit `mod_sideline_testsuite` (`--fail-on-warning --fail-on-skipped --fail-on-incomplete`) | **421/421**, 29 569 Assertions, 0 Skips, in jeder Zelle (vorher 302) |
| PHPUnit `slcmod_assign_testsuite`, `slcmod_quiz_testsuite` | grün in jeder Zelle, 0 Skips |
| Behat (Chrome) | **17/17 Szenarien, 225/225 Schritte** in jeder Zelle (vorher 13 und 166) |
| Races `tools/race/run.php` (echte Parallelprozesse) | **5/5 in jeder Zelle**, PostgreSQL und MariaDB. Gegenprobe Kontingent mit dem Code von 0.3.2: 2 von 20 bzw. 20 von 20 Prozessen kommen durch, mit 0.4.0 genau 1 |
| Frontend (`js-checks.sh`, Moodle 5.3) | ESLint 0, `eslint:react` 0, `tsc` strict 0, Jest React **23/23**, AMD **19/19** (beide mit axe), ESM- und AMD-Bundle aktuell |
| Playwright | **6/6** auf Moodle 5.3 (React-Panel) und 4.5 (AMD-Panel). Auf 5.3 schlug der Gruppen-Journey im ersten Lauf einmal fehl; die zwei weiteren Läufe auf frischen Daten waren grün |
| Upgrade 0.3.2 → 0.4.0 (`upgrade.sh`, wie der CI-Job) | 4.5/pgsql und 5.3/mariadb: Schema ohne Abweichung, `upgrade_verify.php` 9/9. Zusätzlich die Entwicklungs-Sites 4.5/pgsql und 5.3/pgsql mit echten Daten aktualisiert (Schema ok); 5.3/mariadb neu installiert (Schema ok) |
| Gruppenpfad, Abfrageprofil (`tools/bench_groups.php`, 5.3) | alle 12 Kombinationen im Budget (≤ 12 Lesezugriffe warm, ≤ 300 ms kalt), siehe unten |
| Gruppenpfad über HTTP (k6, 5.3, 50 Lernende in 5 Gruppen, alle teilen, 20 gleichzeitig für 60 s plus Race) | PostgreSQL: 2182 Anfragen, 0 Fehler, Cache-Treffer p95 652 ms, nach Zustandswechsel p95 693 ms, Race max. 613 ms. MariaDB: 3915 Anfragen, 0 Fehler, p95 226 ms bzw. 266 ms, Race max. 434 ms. Beide: `verify_load.php` OK, 16 von 21 Cachezeilen mit Gruppenaussage |

Abfrageprofil des Gruppenpfads (eine Empfängerin, Lesezugriffe kalt/warm, Zeit kalt/warm in ms):

| Personen | Gruppen | PostgreSQL | MariaDB |
|---|---|---|---|
| 100 | 0 / 10 / 50 / 200 | 15/4, 14/7, 12/7, 12/7 · 9 bis 13 ms | 9/4, 10/5, 8/5, 8/5 · 6 bis 10 ms |
| 1000 | 0 / 10 / 50 / 200 | 11/4, 14/7, 12/7, 12/7 · 6 bis 15 ms | 7/4, 10/5, 8/5, 8/5 · 3 bis 10 ms |
| 5000 | 0 / 10 / 50 / 200 | 13/4, 14/7, 12/7, 12/7 · 6 bis 25 ms | 9/4, 10/5, 8/5, 8/5 · 6 bis 32 ms |

Die Lesezugriffe hängen nicht von der Zahl der Personen oder Gruppen ab. Kalte Aufrufe enthalten den Neuaufbau der Moodle-Caches. Bei 5000 Personen ohne frische Tabellenstatistik (direkt nach Masseneinfügen) braucht PostgreSQL rund 400 ms; das Werkzeug aktualisiert die Statistik deshalb vor der Messung, wie es auf einer laufenden Website der Fall ist.

Unterschiede zu GitHub: ChromeDriver unter Xvfb statt Selenium-Container; PostgreSQL 17.11 und MariaDB 11.4.13 lokal statt als Dienst-Container.

---

## #1 PHP 8.1 und älter aus allen Testmatrizen entfernen

| AK / DoD | Status | Fundstelle / Nachweis |
|---|---|---|
| AK1 Kein Testjob und keine Matrixzelle mit PHP ≤ 8.1 | ✔ | `moodle-plugin-ci-main.yml`: Matrix 4.5/8.2/pgsql statt 8.1, Coverage auf 8.3. `moodle-plugin-ci-dev.yml`: 4.5/8.2. Kein `php-version: '8.1'` und keine Matrixzelle mit 8.1 mehr in `.github` (`grep -rn "php: '8.1'\|php-version: '8.1'" .github` ist leer). |
| AK2 Gültige Kombinationen, funktionale Abdeckung bleibt | ✔ | Weiterhin 8 Zellen 4.5 bis 5.3 mit PHPUnit, Adapter-Suites, Races und Behat. Moodle 4.5 läuft mit PHP 8.2 und 8.3 (beides unterstützt). |
| AK3 Hinweise, Kommentare, Doku | ✔ | Workflow-Kopf, `docs/environment-setup.md` 4.3, `docs/CHANGELOG.md`. `version.php` bleibt wie verlangt unverändert. |
| DoD1 Matrizen geprüft | ✔ | siehe AK1 |
| DoD2 Funktionale Läufe auf den verbleibenden PHP-Versionen grün, Skips zählen nicht | ✔ | `--fail-on-skipped --fail-on-incomplete` in allen PHPUnit-Schritten; lokale Matrix siehe oben. GitHub: nach Push |

## #2 Release-Gates entfernen, Funktionstests behalten

| AK / DoD | Status | Nachweis |
|---|---|---|
| AK1 `maturity-gate` und Abhängigkeiten entfernt | ✔ | Jobs `maturity-gate` und `ecosystem-lockstep` gelöscht. `ci-complete` braucht nur noch `test, frontend, upgrade, release-artefact, coverage, stale-files`. |
| AK2 Keine Reife-, Sign-off-, Lockstep- oder Metadaten-Formalie entscheidet | ✔ | Im Workflow gibt es keine Auswertung von `MATURITY_` oder Versionsgleichheit mehr. Die Abhängigkeit der Adapter von `mod_sideline` prüft Moodle selbst bei der Installation. |
| AK3 Funktionale Prüfungen bleiben aktiv und sichtbar | ✔ | Matrix, Frontend, Upgrade, Artefaktinhalt, Coverage und veraltete Dateien laufen weiter, jede mit eigenem Status. |
| AK4 Keine Umbenennung, Doku aktualisiert | ✔ | `ci-complete` aggregiert nur, ohne eigene Formalien. Doku: `environment-setup.md` 4.2, CHANGELOG |
| DoD1/2 Workflow-Graph und Ausführung auf aktuellem Commit | ◐ | YAML geprüft (Jobs: `test, release-artefact, coverage, stale-files, frontend, upgrade, ci-complete`). GitHub-Run nach Push |

## #3 (P0) Widerruf, Cache und Antwort atomar

| AK / DoD | Status | Nachweis |
|---|---|---|
| AK1 Lock-Timeout ohne personenbezogene Ausgabe | ✔ | `commentary_service::neutral()`: nur der Grundtext, nichts gespeichert, keine KI. Tests `delivery_atomicity_test::test_a_lock_timeout_yields_the_generic_text_without_ai_and_without_storing` und `…_after_a_withdrawal_yields_no_output_at_all`. Ein Timeout beim Widerruf löscht trotzdem: `test_a_withdrawal_whose_lock_times_out_still_removes_the_texts` |
| AK2 CAS-Konflikt oder Widerruf verhindert Auslieferung vorher berechneter Inhalte | ✔ | Rückgabewert von `commentary_cache::put()` wird ausgewertet. Bei einem Konflikt wird neu berechnet, beim zweiten Konflikt kommt nur der Grundtext. Tests `test_an_invalidation_during_generation_discards_the_text_and_computes_it_again`, `test_repeated_invalidations_yield_only_the_generic_text`, `cache_concurrency_test::test_an_event_during_ai_generation_discards_the_text` |
| AK3 Recheck vor jeder Ausgabe (Cache-Treffer, Timeout-Fallback, Debug-Simulation) | ✔ | `commentary_service::deliverable()` an allen Rückgabestellen. Tests `test_a_cache_hit_passes_the_final_gate`, `test_the_debug_simulation_respects_a_withdrawal`, `test_a_withdrawal_whose_cleanup_never_ran_is_still_caught_by_the_final_gate` |
| AK4 Nebenläufigkeit deterministisch abgedeckt | ✔ | 9 deterministische Verschränkungen in `delivery_atomicity_test`. Echte Parallelprozesse in `tools/race/run.php`: „withdrawal during generation“, „withdrawal after generation“, „invalidation during generation“ |
| DoD1 PHPUnit mit Verschränkungen und Lock-Fehlern | ✔ | siehe AK1 bis AK4 |
| DoD2 Behat-Negativtest: Widerruf während einer AJAX-Anfrage | ✔ | Behat „A withdrawal in another tab stops the open panel at its next request“ |
| DoD3 Security- und Privacy-Review mit Belegen | ◐ | Protokoll und Begründung in `docs/privacy/DATENSCHUTZ.md`, Abschnitt 3. Die Ausnutzbarkeit der Lücke von 0.3.2 ist als Gegenprobe gezeigt (siehe #8). Das Review durch eine zweite Person steht aus |

## #4 (P0) Custom-Rule-Intent vor der KI datenschutzsicher

| AK / DoD | Status | Nachweis |
|---|---|---|
| AK1 Vollständige Allowlist für Anbieter-Eingaben, sonst sicher verweigern | ✔ | Allowlist `pseudonymizer`. Jeder Freitext (Intent, Persona-Stil, Persona-Vorschau) läuft durch `prompt_text_guard`. Bei einem Fund wird nichts gesendet (`ai_gateway::STATUS_UNSAFE_INPUT`) |
| AK2 Namen, E-Mails, URLs, genaue Daten, Kurs- und Modul-IDs, Freitext mit Personenbeispielen bleiben lokal | ✔ | `prompt_guard_test`: 21 Muster, Namen aller Kursteilnehmenden, eigener Name. Integration über den Service: kein Aufruf, Vorlagentext, 6 Fälle |
| AK3 Nur identifikationsfreie Fakten, sonst statischer Fallback | ✔ | `test_an_unsafe_teacher_text_is_never_sent_and_the_template_is_served`, `test_a_clean_teacher_text_is_sent_as_intent_with_tokens_only` |
| AK4 Prompt-Injection im Intent, Ergebnis personaunabhängig | ✔ | Injection-Muster (de, en, fr) werden abgelehnt. Kommt eine Injection durch, prüfen die Antwortprüfungen unabhängig vom Prompt; Test für jede eingebaute Persona (`test_an_injected_answer_is_still_checked_independently_of_the_persona`). Ein adversarialer Persona-Stil wird gar nicht gesendet (`persona_test`) |
| DoD1 Adversariale PHPUnit- und HTTP-Body-Tests über den echten KI-Adapter | ✔ | `prompt_guard_test`; `ai_integration_test` prüft den HTTP-Body am echten `aiprovider_openai` mit Guzzle-Mock |
| DoD2 Behat: personalisierte Lehrendenvorlage mit KI und mit Fallback | ✔ | Behat „A teacher's own text is phrased by the AI, a text with a name of the course never leaves the site“ (simulierte KI, nur auf Behat-Sites aktiv: `behat_text_generator`) |
| DoD3 Dokumentiertes Privacy-Review mit Falsifikationsfällen | ◐ | `docs/privacy/DATENSCHUTZ.md`, Abschnitt 2, mit Restrisiken. Die fachliche Freigabe durch DSB steht aus (D1 bis D5) |

## #5 Legacy-Kursquellen ohne Verantwortliche

| AK / DoD | Status | Nachweis |
|---|---|---|
| AK1 Quellen ohne nachweisbare Identität werden bis zur Re-Autorisierung nicht ausgewertet | ✔ | `scope_resolver::authorised()` verlangt `editorid > 0`. Test `source_authority_test::test_a_source_without_a_teacher_on_record_is_not_evaluated` |
| AK2 Migration mit nachvollziehbarem Hinweis und Protokoll | ✔ | Upgrade-Schritt 2026110300: Zeilen bleiben zur Bestätigung erhalten, Konfigurationsversion steigt, Texte werden verworfen, Eintrag im Upgrade-Log je Instanz. Das Formular zeigt die Zahl unbestätigter Quellen, Bestätigung durch Speichern. Test `test_the_upgrade_records_affected_instances_and_drops_their_texts`, `upgrade_verify.php` |
| AK3 Rechteentzug deaktiviert sofort, auch alte Datensätze | ✔ | `test_a_revoked_role_of_the_teacher_stops_old_and_new_sources`, `test_a_deleted_course_is_no_source`, `course_branch_test` |
| AK4 Cache und Empfehlungen verlieren die Inhalte | ✔ | Observer `role_changed` markiert alle Instanzen, deren Quellen die Person autorisiert hat. Der Fingerabdruck folgt dem Stand (`test_moving_a_course_out_of_the_branch_changes_the_next_delivery`) |
| DoD1 PHPUnit: editor = 0, Rollenentzug, gelöschte Kurse, Kategorie | ✔ | `source_authority_test` (7 Tests) |
| DoD2 Behat E2E bei Lehrenden- und Lernendenwechsel | ◐ | Abgedeckt durch PHPUnit über Service und Observer. Ein eigenes Behat-Szenario fehlt; ein Kursverbund braucht in Behat mehrere Kurse mit Kategorierechten |
| DoD3 Upgrade-Pfad auf 4.5 und 5.3 geprüft | ✔ | Upgrade-Job (`tools/ci-local/upgrade.sh`): 0.3.2 → 0.4.0, siehe Gesamtergebnis |

## #6 Ereignisarten des Gruppenmodus

| AK / DoD | Status | Nachweis |
|---|---|---|
| AK1 Ereignisarten konfigurierbar und datenschutzgeprüft | ✔ | Feld `groupevents` (geöffnet, abgeschlossen, bestanden), Formular, Backup, Transfer |
| AK2 Unterscheidung Nutzung, Abschluss, Bestehen, Zeitfenster, unbekannter Status; aggregiert | ✔ | `group_service::events()`. Ohne Abschlussverfolgung gibt es keine Aussage. Noten, Leistungsänderungen und Zugang sind bewusst nicht freigegeben (Matrix) |
| AK3 Konsistent für geschlossene/offene, namentliche/anonyme Varianten | ✔ | eine Statement-Logik für alle Kreise |
| AK4 Keine Leistungsentscheidung aus Ereignissen anderer, keine Fehlschläge | ✔ | `test_event_types_are_configurable_and_failures_are_never_told`, `group_mode_test::test_7…` |
| AK5 Neue Quellen in Zustimmung und Widerruf | ✔ | Eine neue Ereignisart erhöht die Gruppenversion (`test_a_new_event_type_asks_again`) |
| DoD1 Matrix fachlich freigegeben | ◐ | Matrix in `docs/privacy/GRUPPENAGGREGATE.md`, Abschnitt 1. Die Freigabe durch Produktverantwortung und DSB steht aus (D4) |
| DoD2 PHPUnit und Mehrbenutzer-Behat | ✔ | `group_privacy_test`, `group_mode_test`, Behat-Gruppenszenarien (2), Playwright „a group member sees a named statement only while the other person shares“ |

## #7 Gruppen-N+1 und unbeschränkte Datenmengen

| AK / DoD | Status | Nachweis |
|---|---|---|
| AK1 Keine lineare Abfrage je Mitglied oder Gruppe | ✔ | `group_service::events()` mengenbasiert, `consent_manager::processing_sql()`, `users_allowing_processing()`. Tests `test_queries_do_not_grow_with_groups_or_members`, `test_sharing_users_checks_consent_in_one_query` |
| AK2 Speicher, CPU, Ergebnisgröße begrenzt | ✔ | Es werden keine Teilnehmendenlisten mehr geladen; Ergebnis nur Personen mit Ereignis im Fenster. Höchstens eine Aussage |
| AK3 Budgets für 0/10/50/200 Gruppen und 100/1000/5000 Personen | ✔ | `tools/bench_groups.php`, Budget 12 Lesezugriffe und 300 ms je Aufruf. Messwerte siehe Gesamtergebnis |
| AK4 Prüfungen bleiben stark | ✔ | Einschreibung, Freigabe, Version und Zustimmung stehen in derselben Abfrage |
| DoD1 Profiler und k6 auf 5.3 mit beiden Datenbanken, Cache-Treffer und -Fehlschläge | ✔ | Abfrageprofil und k6 auf PostgreSQL und MariaDB, Treffer und Neuerzeugung getrennt gemessen (siehe Gesamtergebnis) |
| DoD2 Keine N+1-Regression, Randfälle | ✔ | siehe AK1 |

## #8 KI-Kontingente atomar

| AK / DoD | Status | Nachweis |
|---|---|---|
| AK1 Websiteweit atomar | ✔ | `ai_usage::reserve()` unter der Sperre `aibudget`, fail closed |
| AK2 Token-Budget mit schlechtestem Fall | ✔ | Reservierung = Prompt-Schätzung + `MAX_RESPONSE_TOKENS` |
| AK3 Verwaiste und gescheiterte Aufrufe nachvollziehbar | ✔ | `timecompleted = 0` heißt offen, zählt weiter, erscheint in der Zusammenfassung. Ein Anbieterausfall schließt als gescheitert ab |
| AK4 Freigabe bei Admin-Änderung und Ausfall getestet | ✔ | `ai_budget_test` (7 Tests) |
| DoD1 Parallel ≥ 20 Nutzer/Instanzen, Restbudget 1, PG und MariaDB | ✔ | `tools/race/run.php`: 20 Prozesse aus 20 Instanzen, genau 1 erhält den letzten Aufruf und genau 1 die letzten Tokens. **Gegenprobe mit dem Code von 0.3.2: 2 von 20 bzw. 20 von 20** |
| DoD2 Tatsächliche Aufrufe überschreiten nie die Grenzen | ✔ | siehe DoD1 |

## #9 Erneute Zustimmung bei Erweiterung des Freigabekreises

| AK / DoD | Status | Nachweis |
|---|---|---|
| AK1 Jede Erweiterung (Kreis, Gruppen, Gruppierungen, Ereignisarten) verlangt neue Entscheidung | ✔ | `instance_config::refresh_group_circle()`, `apply_version_bumps()` |
| AK2 Einschränkungen wirken sofort | ✔ | `test_adding_a_group_asks_sharing_learners_again_removing_one_does_not` |
| AK3 Alle Änderungspfade | ✔ | Formular, Observer (`group_created`, `group_updated`, `group_deleted`, `grouping_group_(un)assigned`, `grouping_updated`), Import, Wiederherstellung |
| AK4 Veraltete Freigabe liefert keinen alten Text | ✔ | Gruppenversion im Fingerabdruck über die Gruppenfakten; `test_a_change_during_ai_generation_delivers_no_statement_of_the_old_circle` |
| DoD1 Tests: Gruppe hinzu, Gruppierung erweitert, Gruppe entfernt, Änderung während KI | ✔ | `group_privacy_test`, Behat „Admitting a further group asks sharing learners to decide again“ |
| DoD2 Privacy-Texte und Testmatrix | ✔ | Hilfetexte `groupevents_help`, `docs/privacy/GRUPPENAGGREGATE.md`, Abschnitt 2 |

## #10 Vollständiger, sicherer Konfigurationstransfer

| AK / DoD | Status | Nachweis |
|---|---|---|
| AK1 Kursquellen, Kategorien, Gruppen, Gruppierungen, Personas selektiv übertragbar | ✔ | Teile `sources`, `groups`, `personas` (Format Version 3) |
| AK2 Eindeutige Zuordnung oder ausdrücklicher Konflikt | ✔ | `map_activity()`: Typ, Name, Abschnitt. Mehrdeutig heißt Warnung und keine Zuordnung. Gruppen werden über ID-Nummer oder Namen zugeordnet, mehrdeutig heißt Warnung (`test_activities_with_the_same_name_are_never_guessed`) |
| AK3 Fehlende Referenzen sicher, Warnung, ggf. neue Zustimmung | ✔ | `test_sharing_is_never_switched_on_for_all_groups_implicitly`, `test_older_files_never_admit_all_groups` |
| AK4 Keine Personendaten, Tokens, Logs, Caches; unbekannte Personas inaktiv | ✔ | `custom_rules_test::test_export_import_round_trip…`, `test_persona_definitions_only_for_administrators_and_only_as_drafts` |
| DoD1 Rundreise mit Duplikaten, fehlenden Gruppen und Personas | ✔ | `config_transfer_test::test_sources_groups_and_personas_travel_and_are_resolved_by_name` |
| DoD2 PHPUnit und Behat für Transfer und Backup/Restore auf 4.5 und 5.3 | ✔ | Matrix (alle 8 Zellen); Behat „Teachers export the configuration“ |

## #11 Multiple Choice mit Mehrfachauswahl

| AK / DoD | Status | Nachweis |
|---|---|---|
| AK1 Einfach- und Mehrfachauswahl getrennt, Min/Max, Wirkungen | ✔ | `question_type::normalise_config()`, Formularfelder, Verrechnung `priority` oder `none` |
| AK2 Strenge Prüfung, keine Duplikate, unbekannte Optionen abgelehnt | ✔ | `multiple_choice_test::test_values_are_validated_strictly` (12 Fälle) |
| AK3 Darstellung, eigene Antworten, Bearbeiten, Löschen, Import, Export, Backup | ✔ | React und AMD (Checkboxen mit Hinweis über `aria-describedby`), `my_answers`, Export- und Backup-Konfiguration als JSON |
| AK4 Antworten nicht an KI oder Lehrende | ✔ | unverändert, `dialog_test::test_free_text_is_never_sent_to_ai`, `access_security_test` |
| DoD1 PHPUnit leer, mehrfach, ungültig, Kollision | ✔ | `multiple_choice_test` |
| DoD2 Behat und React für Tastatur, Screenreader, Antwortübersicht | ✔ | Behat mit Accessibility-Prüfung, Jest React und AMD (mit axe), Playwright per Tastatur |

## #12 Aggregate gegen Differenzangriffe

| AK / DoD | Status | Nachweis |
|---|---|---|
| AK1 Risikoanalyse | ✔ | `docs/privacy/GRUPPENAGGREGATE.md`, Abschnitt 3 |
| AK2 Unterdrückungs- und Vergröberungsregeln, keine rückrechenbaren Zahlen | ✔ | Mindestgröße 3, Bänder, tagesstabiles Fenster, eine Aussage |
| AK3 Gruppen- und Personenname nur nach aktueller Freigabe für genau diesen Kreis | ✔ | Freigabekreis mit Version (#9) |
| AK4 Keine historischen Differenzantworten im Cache | ✔ | Fingerabdruck, Observer, Widerruf löscht Instanz-Caches |
| DoD1 Negative Tests k = 2 und k = 3, gemeinsame Mitglieder, sequenzielle Abfragen, Sichtbarkeitswechsel | ✔ | `group_privacy_test` |
| DoD2 Privacy-Bewertung und akzeptierte Restrisiken | ◐ | Restrisiken dokumentiert; Bestätigung durch DSB steht aus (D4) |

## #13 Faktencheck mehrsprachiger Übersetzung

| AK / DoD | Status | Nachweis |
|---|---|---|
| AK1 Nie als geprüft ausgeben, wenn nicht prüfbar | ✔ | `ai_gateway::language_supported()` verlangt Prüfregeln; `translate()` lehnt Ziele ohne Regeln ab |
| AK2 Prüfregeln oder deterministischer Fallback mit Status | ✔ | `claim_patterns` für fr, es, it, nl, pt; sonst Vorlage mit Fallback-Kennzeichnung |
| AK3 Tests ohne Zahlen in fr, es, it, nl, pt und gemischt | ✔ | `language_matrix_test` (15 und 1 Fall) |
| AK4 Fehlgeschlagene Übersetzung nie als Erfolg gecacht | ✔ | `test_a_failed_translation_is_never_stored_or_shown_as_ai` |
| DoD1 Adversariale Integrationstests mit Fallback | ✔ | siehe oben, `ai_language_test` |
| DoD2 Alle Sprachprofile in CI | ✔ | Teil der PHPUnit-Matrix |

## #14 Privacy, KI-Kernprotokolle, Debug-Löschung

| AK / DoD | Status | Nachweis |
|---|---|---|
| AK1 Debug-Export mit historischem Ist-Output, Abweichung zur Simulation markiert | ✔ | Protokoll enthält `output` und `ai` (nur Tokens); Export enthält `output_kind`, `output_recorded`, `differs_from_simulation`. Test `trace_history_test` |
| AK2 Privacy-Löschung entfernt Gruppen-Caches unabhängig vom Modus | ✔ | `provider::delete_users()`. Test `provider_test::test_deletion_after_switching_group_mode_off_still_drops_texts_naming_the_user` |
| AK3 Core-AI-Policy, Datenregister, Anbieter-Retention, Lösch- und Auskunftsprozess | ◐ | `docs/privacy/DATENSCHUTZ.md`, Abschnitte 1, 5, 6. Betriebliche Prüfung auf dem Zielsystem und Entscheidungen D2, D3, D5 stehen aus |
| AK4 Aufbewahrung der Protokolle, Zugriffsaudit, keine Einzeldialoge für Lehrende | ✔ | `traceretentiondays`, `auditretentiondays`, `sideline_debuglog`; `access_security_test` |
| DoD1 Privacy-Export und Löschung automatisch, inkl. Modus- und Gruppenwechsel | ✔ | `provider_test` |
| DoD2 Löschung und Aufbewahrung der Core-AI-Protokolle im Zielsystem | ◐ | Die Löschung über `core_ai` ist im Test gezeigt (`ai_integration_test`). Auf dem Zielsystem nachzuweisen |
| DoD3 DSFA-Entscheidung dokumentiert | ◐ | Entscheidungsvorlage D1 bis D5. Die Entscheidung selbst trifft die oder der DSB |

## #15 Frontend-, Security- und Performance-Nachweise auf demselben Commit

| AK / DoD | Status | Nachweis |
|---|---|---|
| AK1 React-TS strict, ESM-Build, Jest, A11y automatisiert | ✔ | Neuer Job `frontend` in der Main-CI (`tools/ci-local/js-checks.sh`) |
| AK2 Playwright mit Tastatur, Screenreader-Prüfung, Consent- und Gruppenflüssen auf 5.3 | ✔ / ◐ | 6 Journeys, 6/6 auf 5.3 und 4.5 (neu: Mehrfachauswahl per Tastatur, Gruppenaussage und Widerruf). `playwright.yml` läuft auf Wunsch nur manuell: Die Run-ID entsteht nach dem Push per „Run workflow“ |
| AK3 `slcmod_assign`- und `slcmod_quiz`-Tests ausgeführt, Skips zählen nicht | ✔ | Schritt „PHPUnit tests of the adapters“ in jeder Zelle, `--fail-on-skipped` |
| AK4 Upgrade, Restore, Privacy, Security, Nebenläufigkeit, Performance mit Ausführungsberichten | ✔ | Jobs `upgrade` (Artefakt mit Logs), Races (`ci-logs/race.log` im Fehlerbericht-Artefakt), PHPUnit-Testdox im Artefakt |
| AK5 Keine Reife-Gates, kein PHP ≤ 8.1 | ✔ | #1, #2 |
| DoD1 Je Testfamilie SHA, Run-ID, Befehle, Zahlen, Skips, Artefakte | ◐ | lokal siehe Gesamtergebnis; GitHub nach Push |
| DoD2 Unterschiede lokal/GitHub beseitigt und dokumentiert | ✔ | `docs/environment-setup.md`, Abschnitt 4. Verbleibend: ChromeDriver statt Selenium-Container, lokale statt containerisierte Datenbanken derselben Versionen |

## #16 Kategorieverbund-Invalidierung und Rechtewechsel

| AK / DoD | Status | Nachweis |
|---|---|---|
| AK1 Ereignisse aus Kursbereichen invalidieren, oder die nächste Auslieferung ist nachweislich aktuell | ✔ | `commentary_cache::instances_observing()` mit Kursbereichspfad, `mark_category_dirty()`; Observer `course_updated`, `course_deleted`, `course_category_updated` und `_deleted`, `role_capabilities_updated`. Test `source_authority_test` |
| AK2 Gruppen-, Privacy- und Rollenwechsel entziehen sofort | ✔ | Observer und Fingerabdruck; `cache_concurrency_test::test_rights_and_membership_events_mark_the_cache_dirty` |
| AK3 Textinhalt und erzeugte Antworten, nicht nur Aktionen | ✔ | Der Fingerabdruck umfasst alle Empfehlungen und Fakten; `sanitize()` prüft zusätzlich die Links |
| AK4 Kein unbeschränktes globales Löschen als Standard | ✔ | Nur Instanzen mit Quellen oder Kursbereichen; Test `test_category_events_mark_instances_with_branches_dirty` |
| DoD1 PHPUnit: Kategorie, kursübergreifende Bewertung und Abschluss, Mitgliedschaft und Rolle | ✔ | `source_authority_test`, `cache_concurrency_test` |
| DoD2 Behat mit aktiver Sitzung und vorgefülltem Cache | ◐ | Behat „A withdrawal in another tab“ und „Admitting a further group“ laufen mit aktiver Sitzung und gefülltem Cache. Ein Behat-Szenario für Kursbereiche fehlt (wie #5 DoD2) |

## #17 Sprachmatrix und korrekter Fallback

| AK / DoD | Status | Nachweis |
|---|---|---|
| AK1 Sprachunterstützung dokumentiert und konfigurierbar | ✔ | `docs/SPRACHMATRIX.md` |
| AK2 Vorlage nie als übersetzt | ✔ | Quelle `translated` nur nach dem Übersetzungsschritt; Panel-Hinweise `translatedNote` und `languageFallback` |
| AK3 Personas, Interviews, Feedback übersetzt oder verständlicher Fallback | ✔ | Fallback-Hinweis im Panel; Personas mit Eröffnungssätzen je Sprache |
| AK4 Keine erfundenen Fakten durch Übersetzung | ✔ | #13 |
| DoD1 Matrix de, en, fr und weitere, Laufzeitwechsel | ✔ | `language_matrix_test::test_switching_the_language_at_runtime_changes_text_and_cache` |
| DoD2 PHPUnit, Behat, React-E2E der lokalisierten Oberfläche | ✔ / ◐ | PHPUnit, Jest (React und AMD). Behat und Playwright auf Englisch, weil die CI-Sites nur das englische Paket haben |

## #18 Import als Rechte- und Zustimmungsgrenze

| AK / DoD | Status | Nachweis |
|---|---|---|
| AK1 Gleiche Kurs-, Kategorie- und Gruppenrechte wie im Formular | ✔ | `preview_sources()` prüft `moodle/course:manageactivities` je Ziel, `save_sources()` und `save_source_categories()` prüfen erneut; Gruppen nur aus dem Zielkurs |
| AK2 Inkonsistente Konfigurationen abgelehnt, keine implizite Ausweitung auf alle Gruppen | ✔ | `check_structure()`, Teilen ohne aufgelöste Gruppen bleibt aus |
| AK3 Import von Sichtbarkeit und Quellen kann neue Zustimmung auslösen | ✔ | `apply_version_bumps()` und `refresh_group_circle()` nach jedem Import |
| AK4 Negativtests: fehlerhaftes JSON, Duplikate, fremde Kontexte, unberechtigte Ziel-IDs | ✔ | `config_transfer_test::test_malformed_files_are_rejected_as_a_whole` (7 Fälle), `test_duplicate_rules_and_foreign_ids_are_not_taken_over`, `test_sources_the_importer_may_not_manage_are_skipped_with_a_warning` |
| DoD1 Sicherheitsanalyse aller Importpfade, Tests für neues und altes Format | ✔ | Klassendoku `config_transfer`; Versionen 1, 2 und 3 getestet |
| DoD2 Keine Rechteausweitung, keine ungeprüfte Konfiguration | ✔ | siehe AK1 bis AK4 |

## Was vor dem Schließen der Issues noch fehlt

1. Push und GitHub-Läufe: Main-CI und einmal manuell `playwright.yml` auf dem 0.4.0-Commit. Run-IDs in den Issues nachtragen.
2. Fachliche Entscheidungen D1 bis D5 (`docs/privacy/DATENSCHUTZ.md`), insbesondere Freigabe der Ereignismatrix (#6) und der Restrisiken (#12).
3. Behat-Szenarien für Kursverbünde mit Kategorierechten (#5 DoD2, #16 DoD2), falls das Audit die PHPUnit-Abdeckung nicht genügen lässt.
