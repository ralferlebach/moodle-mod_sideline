# Abdeckungsmatrix mod_sideline 0.2.0

Stand: 25.10.2026. Jede Zeile nennt die Umsetzung und den **ausgeführten** Nachweis. Ausgeführt heißt: lokal im Container gelaufen, nicht in GitHub-CI (siehe P-37).

Legende: ✔ umgesetzt und getestet · ◐ teilweise / mit benannter Lücke · ✘ offen

Testorte: `tests/*.php` (PHPUnit, mod_sideline_testsuite), `adapter/*/tests` (slcmod-Suites), `tests/behat/sideline.feature` (Behat), `js/esm/tests` (Jest).
Läufe: PHPUnit 172/172 auf PostgreSQL 17.11 **und** MariaDB 11.4.13 · Behat 11/11 Szenarien (4 mit Headless-Chrome, 2 davon mit axe-WCAG-Prüfung) · Jest 20/20 (inkl. axe) · phpcs Moodle-Standard 0 Fehler / 0 Warnungen.

## Lastenheft

| ID | Status | Umsetzung | Nachweis |
|---|---|---|---|
| L-01 Aktivität mit Textfeldcharakter, mehrfach | ✔ | `display=inline`, `sideline_cm_info_view()`, `set_no_view_link()` | `inline_display_test`, Behat „Two inline instances“ |
| L-02 Beobachtungsbereich | ✔ | `scope_resolver` (Kurs, Abschnitt rekursiv mit Subsektionen, Aktivitäten, Kursverbund) | `scope_test` |
| L-03 Nutzung, Abschluss, Bewertung, Bestehen, Fristen, Zugang, Kursabschluss | ✔ | `state_collector` (Log, Completion, Gradebook, Adapter, Availability), Kursabschluss über `completion_info` | `state_model_test`, `rule_evaluator_test`, Adapter-Tests |
| L-04 Statische Texte, Templates, KI | ✔ | `message_composer`, eigene Regel-Templates, `ai_gateway` | `commentary_service_test`, `custom_rules_test` |
| L-05 Drei Personas | ✔ | `persona_registry` | `ai_pipeline_test` |
| L-06 Mini-Interviews, Überspringen | ✔ | `dialog_manager`, `question_type`, `QuestionForm.tsx` | `dialog_test`, Jest, Behat „Learners answer a question“ |
| L-07 Sprache | ◐ | Templates in de/en, KI in Nutzersprache; Fallback auf Englisch | `state_model_test` (Zeitzone), Jest (keine harten Strings). Lücke: kein gesonderter Übersetzungsschritt (P-24) |
| L-08 KI und Kursverbünde separat schaltbar | ✔ | Einstellungen `aienabled`, `crosscourse` | `commentary_service_test`, `scope_test` |
| L-09 Subplugins `slcmod_*` | ✔ | `db/subplugins.json`, `local\adapter\{base,registry,facts}`, `slcmod_assign`, `slcmod_quiz` | `adapter_registry_test`, Adapter-Suites |
| L-10 Cache bis Zustandsänderung, Zeitabläufe | ✔ | Fingerprint über Empfehlungen, `next_transition`, Observer | `commentary_service_test`, `cache_concurrency_test` |
| L-11 Tippanimation, reduzierte Bewegung | ✔ | `useTypewriter.ts`, `prefers-reduced-motion` | Jest „respects reduced motion“ |
| L-12 Selektiver Export/Import ohne Personendaten | ✔ | `config_transfer`, `transfer.php` | `custom_rules_test` (Rundreise, Konflikte, Migration), Behat |
| L-13 Pseudonymisierung, lokale Auflösung | ✔ | `pseudonymizer`, Tokens für Personen, Gruppen, Aktivitäten, Daten | `ai_integration_test` (HTTP-Ebene), `group_mode_test` |
| L-14 Ohne KI / bei Ausfall | ✔ | Fallback-Kette | `ai_integration_test` (HTTP 500), `commentary_service_test` |
| L-15 Vollständige automatisierte Abdeckung | ◐ | 172 PHPUnit, 11 Behat, 20 Jest | Lücke: GitHub-CI nie gelaufen; nicht jeder Zweig jeder Klasse |
| L-16 Vertraulichkeit | ✔ | keine Lehrenden-Endpunkte, `manage` ohne Leserechte | `external_test`, `dialog_test`, Behat |
| L-17 Admin-Debug-Export | ✔ | `debug_exporter`, `debug.php`, Audit-Log | `debug_export_test`, Behat |
| L-18 Persona-Freigabe | ✔ | `persona_manager`, `personas.php`, Teilmenge, Lernendenwahl | `persona_test`, Behat |
| L-19 Kursverbünde | ✔ | `sideline_source`, Rechte je Quellkurs | `scope_test` |
| L-20 Einwilligungsoption | ✔ | `consent_manager`, Widerruf im Panel | `commentary_service_test`, `access_security_test`, Behat |

## Pflichtenheft

| ID | Status | Umsetzung | Nachweis |
|---|---|---|---|
| P-01 Unabhängige Dimensionen, unknown explizit | ✔ | `activity_state` (inkl. Versuche, Abgabe, Sperrgrund, Nutzung) | `state_model_test`, `custom_rules_test` (unknown erfüllt nie) |
| P-02 Fristen getrennt, Öffnungs-/Schließzeiten | ✔ | Adapter-Fristen nach Overrides, `availability_analyser` | Adapter-Tests, `state_model_test` (AND/OR/NOT) |
| P-03 Core ist Autorität | ✔ | Verfügbarkeit nur aus `cm_info`, Adapter nur ergänzend, typgeprüft | `adapter_registry_test` |
| P-04 Empfehlung mit Ziel; Prüfung vor Auslieferung | ✔ | `message_composer::sanitize()` auch für Cache | `access_security_test` |
| P-05 Regeln mit Bedingungen, Cooldown, Häufigkeit | ✔ | `rule_schema`, `rule_evaluator::rule_custom()` | `custom_rules_test` |
| P-06 Deterministische Kollision | ✔ | Kategorie, Priorität, Schlüssel | `rule_evaluator_test` |
| P-07 Templates mit Sprache, Version, sicherer Ausgabe | ◐ | Sprachpakete und eigene Templates, reine Textausgabe | Lücke: keine Versionsnummer je Template (Version über `configversion`/`RULES_VERSION`) |
| P-08 Fallback-Reihenfolge | ✔ | `commentary_service` | `commentary_service_test` |
| P-09 KI ergänzt keine Fakten | ◐ | Validator: Struktur, Tokens, Zahlen, Links | `ai_pipeline_test`, `persona_test`. Lücke: semantische Halluzinationen nicht vollständig erkennbar |
| P-10 Prompt-Schichten | ✔ | `prompt_builder` (Regeln zuerst, Persona, Absicht eigener Regeln, Fakten) | `persona_test` (Reihenfolge) |
| P-11–P-13 Persona-Grenzen | ✔ | Stilvorgaben, Validator | `ai_pipeline_test` |
| P-14 Custom-Personas | ◐ | versioniert, Freigabe, Prüfregeln | `persona_test`. Lücke: keine Vorschau einer Persona |
| P-15 Überspringen, Häufigkeit begrenzt | ✔ | Instanz- und Fragen-Cooldown | `dialog_test` |
| P-16 Fragetypen | ✔ | MC, Freitext, Slider, semantisches Differenzial | `dialog_test`, Jest |
| P-17 Antworten, Aufbewahrung, Freitext nie an KI | ✔ | `answerretentiondays`, Freitext ohne Wirkung | `dialog_test`, `debug_export_test` |
| P-18 Nur erklärbare Wirkungen | ✔ | geschlossene Effektliste, Anzeige der Wirkung | `dialog_test`, Behat |
| P-19 Bearbeiten/Löschen | ✔ | `answer_question`, `delete_answer` | `dialog_test`, Jest, Behat |
| P-20 KI über `core_ai\manager` | ✔ | `core_text_generator` | `ai_integration_test` (echter Provider, gemocktes HTTP) |
| P-21 Placement-Entscheidung | ✔ | ADR-AI-01: kein eigenes Placement, eigene Capability `mod/sideline:useai` | `docs/adr/ADR-AI-01.md`, `ai_integration_test` |
| P-22 Nichts Identifizierendes an Provider | ✔ | Allowlist, Tokens | `ai_integration_test` prüft den HTTP-Body |
| P-23 Rückauflösung nach Validierung | ✔ | Auflösung nach Validator und Teilnehmer-Recheck | `group_mode_test` |
| P-24 Übersetzungsschritt | ✘ | nicht umgesetzt; Generierung direkt in Nutzersprache, sonst Template | — |
| P-25 Policy, Ausfall, Limits | ✔ | Policy, Capability, Stundenlimit, Fehler | `ai_integration_test`, `commentary_service_test` |
| P-26 Core-/Provider-Protokolle prüfen | ◐ | Core speichert nachweislich nur Token-Prompts | `ai_integration_test`. Lücke: rechtliche Prüfung |
| P-27 Cache-Key | ✔ | Fingerprint über Nutzer, Instanz, Konfiguration, Regeln, Persona-Version, Sprache, Empfehlungen | `commentary_service_test` |
| P-28 Invalidierung | ✔ | Completion, Grade, Versuche/Abgaben (Adapter), Struktur, Rollen, Einschreibung, Gruppen, Zeit | `cache_concurrency_test` |
| P-29 Observer nur markieren; atomare Versionskontrolle | ✔ | `stamp`, bedingtes UPDATE | `cache_concurrency_test` |
| P-30 Keine Neuerzeugung, nie geteilt | ✔ | | `commentary_service_test` |
| P-31 Admin-Einstellungen | ◐ | KI, Kursverbund, Personas, Limits, Retention, Tracing | Lücke: keine standortweite Interview-Policy |
| P-32 Lehrende: Scope, Regeln, Templates, Persona, Dialoge | ✔ | Formular, `rules.php`, `questions.php` | Behat |
| P-33 Transfer mit Version, Vorschau, Konflikten, Mapping | ✔ | `config_transfer` | `custom_rules_test` |
| P-34 Backup/Restore, Privacy | ✔ | `backup/moodle2` (Konfiguration, Scope, Gruppen, Regeln, Fragen) | `backup_restore_test`, `debug_export_test` |
| P-35 Tests je Zustand, Regel, Persona, Dialogtyp, Subplugin, Recht | ◐ | siehe Testorte | Lücke wie L-15 |
| P-36 Negativtests | ✔ | | `access_security_test`, `state_model_test`, `rule_evaluator_test` |
| P-37 Matrix Moodle 5.3, PostgreSQL/MariaDB, CI | ◐ | lokal PG 17 und MariaDB 11.4, Chrome 153 | Lücke: kein GitHub-CI-Lauf |
| P-38 Barrierefreiheit | ✔ | native Controls, Labels, Fokus, aria-live, reduzierte Bewegung | Jest (axe), Behat mit axe (WCAG 1.3.1, 1.4.1, 4.1.2) |
| P-39 Performance-Budgets | ✔ | Budgets siehe unten, `tools/bench.php` | Messung unten, `cache_concurrency_test` |
| P-40 Keine Lehrenden-Einsicht | ✔ | | `external_test` |
| P-41 Debug-Export | ✔ | | `debug_export_test` |
| P-42 Persona-Policy | ✔ | | `persona_test` |
| P-43 Kursverbünde | ✔ | | `scope_test` |
| P-44 Moodle AI Policy | ✔ | | `ai_integration_test` |
| P-45 Einwilligung vor Verarbeitung | ✔ | | `commentary_service_test` |
| P-46 Protokollierung | ✔ | `sideline_consent_log` mit Art (Analyse, Freigabe, Name) | `group_mode_test`, `commentary_service_test` |
| P-47 Widerruf | ✔ | Sperre plus Recheck | `access_security_test`, `group_mode_test` |
| P-48 Privacy API | ✔ | alle 9 personenbezogenen Tabellen | `debug_export_test`, `provider_test` |
| P-49 Testabnahme | ✔ | | wie oben |

## Gruppenmodus (Konzept V1.2)

Entscheidungen (vom Auftraggeber mit „Alle umsetzen“ übernommen): nur innerhalb des Kurses; nur aktives Opt-in; keine Negativereignisse; nur Abschluss-Aggregate, Unterdrückung unter `groupminsize`.

| ID | Status | Nachweis |
|---|---|---|
| L-G01 Einzelperson | ✔ | `group_mode_test` 1; Lehrende erhalten nie Gruppenaussagen (`review_findings_test` 1) |
| L-G02 Geschlossene Gruppe, namentlich/allgemein | ✔ | `group_mode_test` 2, Behat Gruppenszenario |
| L-G03 Offene Gruppen, drei Stufen | ✔ | `group_mode_test` 3 |
| L-G04 Zugelassene Gruppen/Gruppierungen | ✔ | `group_mode_test` 3 (nicht zugelassen) |
| L-G05 Getrennte Freigabe, Namen gesondert | ✔ | `group_mode_test` 2 |
| L-G06 Widerruf inkl. Cache und laufender Erzeugung | ✔ | `group_mode_test` 6, Behat |
| L-G07 Transparenz | ✔ | Freigabetext im Panel, Jest |
| L-G08 Nichtteilnahme ohne Nachteil | ✔ | eigener Kommentar unabhängig von der Freigabe |
| P-G01 Mitgliedschaften, Wechsel, unsichtbare Gruppen | ✔ | `group_mode_test` 4, 5 |
| P-G02 Stufen als Obergrenze | ✔ | `group_mode_test` 2, 3 |
| P-G03 Versionierte Entscheidungen, kleine Aggregate, Interviews privat | ✔ | `group_mode_test` 6, 7, 8 |
| P-G04 Nur Abschlüsse, keine Ranglisten | ✔ | `group_mode_test` 7 |
| P-G05 Cache | ✔ | `group_mode_test` 6 |
| P-G06 KI nur Tokens | ✔ | `group_mode_test` 9 |

## Performance-Budgets (P-39) und Messung

Gemessen mit `php tools/bench.php --users=200 --activities=30 --requests=1000 --workers=8` auf PostgreSQL 17, PHP 8.3 CLI, ohne KI:

| Messgröße | Budget | Gemessen |
|---|---|---|
| Erzeugung, p95 | ≤ 100 ms | 35,8 ms |
| Erzeugung, DB-Lesezugriffe | ≤ 30, unabhängig von der Aktivitätenzahl | 22 |
| Cache-Treffer, p95 | ≤ 20 ms | 8,9 ms |
| Cache-Treffer, DB-Lesezugriffe | ≤ 15 | 11 |
| Trefferquote bei 10 % Zustandswechseln | ≥ 80 % | 90,9 % |
| 8 parallele Worker, 1000 Anfragen | 0 Fehler | 0 Fehler, 218 Anfragen/s |
| KI-Aufrufe | ≤ `aihourlycap` je Stunde | per Test abgesichert |

Nicht gemessen: echte HTTP-Last mit k6/JMeter gegen einen Webserver und KI-Latenz eines echten Providers.
