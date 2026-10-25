# Arbeitsplanung mod_sideline — Weg zum MVP

> **Stand 25.10.2026:** MVP (0.1.0) und alle Pakete der Audit-Nacharbeit einschließlich Gruppenmodus sind in 0.2.0 umgesetzt.
> Nachweise: `docs/TRACEABILITY.md`, Bearbeitungsstand: `docs/AUDIT_0.2.0.md`. Die Planung unten ist der historische Stand.

Stand: 24.10.2026 · Basis: Lastenheft, Pflichtenheft, Blueprint v1.1 (in diesem Ordner)
Ziel-Plattform: Moodle 5.3 (`MOODLE_503_STABLE`, Version 2026100500, Plugins unter `public/mod/`)

## 1. MVP-Definition

Entschieden am 24.10.2026: **Core-only-Kommentare plus KI-Fallback-Pfad** (Blueprint M1 plus Kern von M3).
Frontend in **React/TypeScript/ESM** gemäß Blueprint.

### Im MVP

| Bereich | Umfang | Anforderungen |
|---|---|---|
| Aktivität | `mod_sideline`, mehrfach pro Kurs, Textfeldcharakter | L-01 |
| Scope | ganze Aktivität-Liste, Abschnitt, ganzer Kurs (keine Kursverbünde) | L-02 |
| Zustandsmodell | `visibility`, `availability`, `completion`, `grade`, `pass`, `timewindow`, `dataquality` aus Core-APIs; unbekannt ≠ negativ | P-01 bis P-04 |
| Regeln | fest eingebaute Standardregeln (Erfolg, Nichtbestehen, Frist, Zugangssperre, Wiedereinstieg), deterministische Kollisionsauflösung | P-05, P-06 |
| Templates | lokalisierte Texte de/en, HTML-sicher, Fallback-Kette | P-07, P-08 |
| Cache | Fingerprint, `next_transition_at`, Dirty-Marker über Observer, Singleflight-Lock | P-27 bis P-30 |
| Persona | feste Registry `reporter`, `cornercoach`, `mentor`; Default je Instanz | P-10 bis P-13, P-42 (ohne Lernendenwahl) |
| KI | `core_ai\manager` mit `generate_text`, Policy- und Standortprüfung, Pseudonymisierung, Validierung, statischer Fallback | P-20 bis P-26, P-44 |
| Einwilligung | optionales Consent-Gate mit Zustandsmaschine | P-45 bis P-47 |
| Service | `get_commentary` mit Capability- und Kontextprüfung | P-40 |
| Frontend | React-Panel mit Tippanimation, `prefers-reduced-motion`, Statusregion | L-11, P-38 |
| Datenschutz | Privacy-Provider für alle nutzerbezogenen Tabellen, Backup/Restore der Instanzkonfiguration | P-48, P-34 |
| Tests | PHPUnit, Jest, ein Behat-Szenario, Mock-AI-Provider | L-15, P-35 |

### Bewusst nicht im MVP (Folgeetappen)

Regel- und Template-Editor (M2), Interviews und Dialoge (M3-Rest), `slcmod`-Adapter für `quiz`/`assign` (M4),
Kursverbünde (M4), Export/Import (M4), Lernendenwahl der Persona, Admin-Debug-Export (Tabelle wird angelegt,
Export folgt), Custom-Personas, Last-/Security-Abnahme (M5).

## 2. Entscheidungen, die vor dem Code geklärt sind

| Thema | Festlegung im MVP | Offen für später |
|---|---|---|
| ADR-04 „letzte Nutzung" | im MVP nur Zeitpunkt der letzten Core-Abschluss-/Bewertungsänderung; Dimension `lastactivity` bleibt sonst `unknown` | Logstore-basierte Definition |
| AI-Placement (P-21) | M0-Spike entscheidet: eigenes `aiplacement_sideline` oder unterstützter Weg ohne Placement | Ergebnis in `docs/adr/ADR-AI-01.md` |
| Retention | Konfigurierbar, Default 90 Tage für Cache, Consent-Nachweis solange Aktivität existiert | Rechtliche Prüfung |
| Einwilligungstexte | Platzhaltertexte, Kennzeichnung „juristisch zu prüfen" | Freigabe Datenschutz |
| Fingerprint-Bestandteile | Nutzer, Instanz, Scope, Faktversionen, Persona, Regelversion, Sprache, Rechte | Erweiterung bei Adaptern |

## 3. Arbeitspakete

Aufwand in Personentagen (PT) als Schätzung für eine Entwicklerin oder einen Entwickler mit Moodle-Erfahrung.
Die Reihenfolge ist der kürzeste Weg zu einem lauffähigen vertikalen Schnitt.

| WP | Inhalt | Ergebnis / Abnahme | PT | Abhängig von |
|---|---|---|---|---|
| WP0 | Umgebung: Moodle 5.3, PostgreSQL, PHPUnit, phpcs, Makefile, CI aus Template | `docs/environment-setup.md` verifiziert, `make check` läuft | 1 | — |
| WP1 | Plugin-Skelett: `version.php`, `lib.php`, `mod_form.php`, `view.php`, `db/install.xml`, `access.php`, Lang de/en | Aktivität anlegbar, Seite rendert | 2 | WP0 |
| WP2 | Zustandsmodell: Value Objects, `ScopeResolver`, `StateCollector` auf Core-APIs | PHPUnit: Zustandsmatrix inkl. unbekannt und unsichtbar | 3 | WP1 |
| WP3 | Regeln, Templates, `MessageComposer`, Kollisionsauflösung | PHPUnit: Regelpriorität, Cooldown, Fallback | 3 | WP2 |
| WP4 | Cache, Fingerprint, `next_transition_at`, Observer, Lock | PHPUnit: Invalidierung, Zeitübergang, Race | 3 | WP3 |
| WP5 | Webservice `get_commentary`, Capability- und Kontextprüfung | PHPUnit: Rechte, kursfremde Nutzer, Lehrende ohne Einsicht | 2 | WP4 |
| WP6 | React/TS-Panel, esbuild-/Grunt-Build, A11y | Jest-Tests, Panel im Browser, Reduced Motion | 3 | WP5 |
| WP7 | KI-Gateway: Policy, Pseudonymisierer, Prompt-Schichten, Validierung, Fallback | PHPUnit mit Mock-Provider, Ausfall → Template | 4 | WP5 |
| WP8 | Consent-Gate und Zustandsmaschine | PHPUnit: vor Zustimmung keine Analyse, Widerruf invalidiert | 2 | WP5 |
| WP9 | Privacy-Provider, Backup/Restore | Privacy-Tests: Export, Löschen, Kontextlöschen | 2 | WP7, WP8 |
| WP10 | Behat-Szenario, CI-Matrix 5.3, Coverage-Floor | CI grün, keine Skips als Ersatz | 2 | WP6, WP9 |
| WP11 | MVP-Abnahme: Review gegen Pflichtenheft, Restrisiken | Abnahmeprotokoll, `CHANGELOG.md` | 1 | alle |

Summe ca. 28 PT. WP6 bis WP8 lassen sich nach WP5 parallelisieren; mit zwei Personen ergibt sich ein realistischer
MVP nach etwa 3 bis 4 Wochen.

## 4. Meilensteine

1. **MS-A, Skelett lauffähig** (nach WP1): Plugin installiert in Moodle 5.3, `make check` und `phpunit` laufen.
2. **MS-B, Statischer Kommentar** (nach WP5): Ein Lernender sieht einen regelbasierten, korrekten Kommentar. Kein JS nötig.
3. **MS-C, Panel** (nach WP6): React-Panel zeigt den Kommentar mit Tippanimation.
4. **MS-D, KI-Pfad und Consent** (nach WP7 und WP8): KI-Text mit Mock-Provider, Fallback bei Ausfall, Consent-Gate.
5. **MS-E, MVP-Abnahme** (nach WP11).

## 5. Qualitätsgates je Arbeitspaket

- `make check` ohne neue Befunde (phpcs, PHPDoc, Mustache, ESLint, tsc).
- PHPUnit-Lauf nachweisbar ausgeführt (Anzahl Tests und Assertions im Protokoll, keine stillen Skips).
- Jeder schreibende Service: sesskey, Capability, Kontext, serverseitige Zielprüfung.
- Keine Moodle-Objekte oder Rohfakten in React-Props.
- `docs/sessions/session-NNN.md` je Arbeitssitzung (Vorlage in `docs/prompt-templates/`).

## 6. Risiken

| Risiko | Wirkung | Gegenmaßnahme |
|---|---|---|
| Core-AI-Hooks für Policy oder Placement weichen von Annahmen ab | WP7 verzögert sich | M0-Spike zuerst, Gateway hinter Interface, Fallback immer aktiv |
| Moodle-5.3-ESM-Toolchain (Import-Map, `@moodle/lms/...`) ändert sich | Panel baut nicht | Panel auf Core-Beispiel `block_timeline` stützen, Build in CI prüfen |
| Cache-Invalidierung unvollständig bei externen Plugins | Veraltete Hinweise | Konservative TTL und periodische Reconciliation per Scheduled Task |
| Pseudonymisierung unzureichend | Datenschutzrisiko | Allowlist statt Regex, keine Freitexte, DSFA vor Produktivfreigabe |
| Behat lokal nicht möglich | Schwächere Abnahme lokal | Behat in CI (`moodle-plugin-ci`) |

## 7. Danach (nach dem MVP)

M2 Regel- und Template-Editor · Interviews · `slcmod_quiz` und `slcmod_assign` · Kursverbünde ·
Export/Import · Debug-Export · Lernendenwahl der Persona · M5 Performance-, Security- und A11y-Abnahme.

## 8. Erweiterung Gruppenmodus (Konzept V1.2) – Planung, noch keine Umsetzung

Quelle: `docs/Konzepterweiterung_Gruppenmodus_v1.2.md` (L-G01–L-G08, P-G01–P-G06). Umgesetzt in 0.2.0 (WP G1–G9), mit den Entscheidungen aus 8.3.

### 8.1 Abhängigkeiten (warum nicht sofort)
Der Gruppenmodus setzt voraus, was laut Audit-Nacharbeit noch fehlt: Subplugin-Adapter für Ereignisse (P1-06), Dialog-Engine für „private Interviewantworten“ (P1-08), Debug-Export/Privacy-Erweiterung (P1-10), Kursverbünde (P1-05) und getrennte Einwilligungsstufen. Die datenschutzkritische Schicht (Veröffentlichung von Ereignissen anderer Personen) darf erst gebaut werden, wenn die offenen Produktentscheidungen (8.3) geklärt und rechtlich geprüft sind.

### 8.2 Arbeitspakete (Schätzung grob, in Personentagen)
| WP | Inhalt | Anforderung | PT |
|---|---|---|---|
| G0 | Entscheidungen 8.3 klären, Rechtsgrundlage/Informationstexte durch Datenschutz prüfen lassen | P-G03 | extern |
| G1 | Datenmodell: Gruppenmodus je Instanz, zugelassene Gruppen/Gruppierungen, Obergrenze der Veröffentlichungsstufe; getrennte, versionierte Entscheidungen (Analyse / Gruppenveröffentlichung / Namensnennung) mit Protokoll | L-G04, P-G01, P-G03 | 4 |
| G2 | `group_scope_resolver` (Core-Gruppen-API, Mehrfachmitgliedschaft, unsichtbare Gruppen, Quellkursrechte) | P-G01 | 3 |
| G3 | `group_visibility_policy` (Empfängerkreis × individuelle Freigabe × Stufe `named`/`named_with_group`/`group_only`/`general`; im Zweifel anonymisieren oder unterdrücken) | P-G02 | 4 |
| G4 | `group_event_collector` und `group_aggregation_service` (Bezugsgrößen Personen/Versuche/Aktivitäten definiert; Schutz gegen Rückidentifikation kleiner Aggregate und exakter Zeitangaben, kein fester k-Wert als Versprechen) | P-G03, P-G04 | 6 |
| G5 | Rule Engine/Templates für Gruppenaussagen, keine Negativereignisse einzelner Personen, keine Ranglisten | P-G04 | 3 |
| G6 | Cache: Fingerprint um Modus, Empfängerkreis, Mitgliedschaften, Freigaben erweitern; Invalidierung bei Gruppenwechsel/-löschung/Widerruf; Reconciliation | P-G05 | 3 |
| G7 | KI-Pfad: nur empfängerfreigegebene, pseudonymisierte Fakten; Rückauflösung nach erneuter Berechtigungsprüfung; Validator gegen fremde Namen | P-G06 | 3 |
| G8 | UI: Transparenzansicht „was wird an wen veröffentlicht“, Freigabeschalter, Widerruf | L-G07, L-G08 | 4 |
| G9 | Privacy API (Freigaben, Caches, Debugdaten), Tests nach Matrix 1–10 des Konzepts inkl. Behat mit mehreren Nutzern/Gruppen | P-G03 | 6 |

Summe ca. 36 PT zusätzlich zu den offenen Audit-Paketen.

### 8.3 Offene Produktentscheidungen (Empfehlung des Entwicklers)
1. **Offener Modus nur im Kurs oder kursübergreifend?** Empfehlung: zuerst nur im Kurs; Kursverbünde erst nach P1-05.
2. **Freigabe nur per aktivem Opt-in?** Empfehlung: ja, getrennt für Veröffentlichung und Namensnennung, Standard „aus“.
3. **Negativereignisse (z. B. nicht bestandene Versuche)?** Empfehlung: nie personenbezogen veröffentlichen; nur Meilensteine und positive Gruppenereignisse.
4. **Zulässige Ereignisarten und Schutz bei kleinen Gruppen?** Empfehlung: Start mit „Aktivität abgeschlossen“ als Aggregat; Mindestgröße als konfigurierbarer Schutz, aber ohne Anonymitätsversprechen, dazu Unterdrückung bei einzelnen Personen.
