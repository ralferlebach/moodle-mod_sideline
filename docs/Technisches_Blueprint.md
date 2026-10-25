# Sideline Learning Coach — Technischer Blueprint v1.0
Stand: 24.10.2026 · Architekturkonzept, kein Implementierungscode

## A. Technologieentscheidung
Moodle 5.3+; `mod_sideline`; React/TypeScript/ESM mit Mustache-React-Autoinit; PHP-Anwendungsdienste und Moodle External Services. React ist nur UI, keine fachliche Autorität. Hinweis: React/ESM-Integration existiert laut Moodle-Dokumentation bereits seit 5.2; 5.3 ist bewusste Mindestzielversion, nicht technischer Ersttermin.

## B. Schichten
1. Präsentation: Kommentar-Panel, Tippanimation, Aktionslinks, Interview-Widgets, Einstellungs-/Vorschauoberfläche.
2. Application: ScopeResolver, StateCollector, RuleEvaluator, RecommendationRanker, MessageComposer, InterviewOrchestrator, CacheCoordinator.
3. Integrationen: Core Completion/Availability/Gradebook/Course/Calendar/Capabilities, SubpluginRegistry, AIAdapter.
4. Persistenz: Instanzkonfiguration, Regel-/Template-/Persona-Versionen, Dialogdefinitionen, Antworten, Zustandsversionen, Cache, Audit-Metadaten.

## C. Datenfluss
Seitenrendering → serverseitige Capability-/Scope-Prüfung → React Mount → Webservice `get_commentary` → Zustandsfingerprint prüfen → bei Treffer Cachetext liefern → andernfalls Fakten aus Core/Adaptern sammeln → Regeln auswerten → erlaubte Empfehlungen berechnen → Template zusammensetzen → bei zulässiger KI: Prompt mit pseudonymisierten Fakten via Moodle AI generieren → Antwort validieren → Platzhalter lokal auflösen → Cache schreiben → Ausgabe. Dialogantwort → `submit_response` → validieren/speichern → gezielte Invalidierung → erneute Bewertung.

## D. Kernverträge (konzeptionell)
- `LearningFact`: scopeRef, factType, typedValue, provenance, observedAt, validUntil, quality, visibilityPolicy.
- `ActivityState`: availability, completion, pass, grade, attempts, timewindows, lastInteraction, nextTransition, unknownReasons.
- `Recommendation`: actionType, targetRef, priority, rationale, eligibility, expiresAt.
- `Commentary`: stateVersion, locale, personaVersion, messageBlocks, allowedActions, interview, nextRefreshAt.
- `Adapter`: supportedModuleTypes(), collectFacts(batch), nextTransitions(batch), declaredCapabilities(), dataSensitivity(), tests.
Keine sensiblen Rohdaten oder Moodle-Objekte ungefiltert in React-Props oder AI-Prompts.

## E. Subplugin-Typ `slcmod`
Ort konzeptionell: `mod/sideline/mod/` mit registriertem Subplugin-Typ `slcmod`; endgültige Verzeichnis-/Komponentenkonvention gegen Moodle 5.3 Plugin Manager validieren. Adapter werden über einen gemeinsamen Registry-Vertrag geladen; zunächst Core-Fallback plus Adapter für `quiz`, `assign`, optional `adaptivequiz`/STACK. Batch-Schnittstellen sind verpflichtend. Jeder Adapter liefert eigene PHPUnit- und Behat-Szenarien; Integrationsmatrix muss Versions-/Installationsabhängigkeiten berücksichtigen. Fehlende Adapter dürfen die Core-Bewertung nicht blockieren.

## F. Moodle AI
Core `core_ai\manager` und `core_ai\aiactions\generate_text` verwenden; Provider und Konfiguration verbleiben im Moodle-AI-Subsystem. Architekturentscheidung vor Umsetzung: eigenes `aiplacement_sideline` für Policy-/Capability-/Reporting-Vertrag oder nachweislich äquivalente unterstützte Integration; keine eigene Provider-Anbindung. Vor Generierung Policy-Akzeptanz, Berechtigungen und Standortfreigabe prüfen. KI-Anfrage enthält ausschließlich allowlist-geprüfte, minimierte, pseudonymisierte Facts und erlaubte Aktionen. Klare Trennung: globale nicht überschreibbare Regeln, Persona-Systemprompt, kursspezifische Stilparameter, Fakten, Ausgabeformat. KI liefert nur Formulierung und optionale Frage aus freigegebenem Fragenpool; Regelentscheidung bleibt deterministisch. JSON-Struktur validieren, HTML sanitizen, IDs/URLs/Zeitangaben nur serverseitig rückauflösen. Bei Modellversagen statisches Template.

### Personas
- `reporter`: sachlich-sportliche Kommentare, gelegentlich kritische Reflexionsfragen.
- `cornercoach`: energische, direkte Ansprache, ohne Beschämung oder Aggression.
- `mentor`: sanfte, lösungsorientierte und systemische Fragen, keine Therapie.
- `custom`: administrativ freigegeben, versioniert, sichere Basisprompt-Schicht unveränderlich.
Lehrende können Persona und Intensität innerhalb der Standort-Policy wählen. Persona-Wechsel invalidiert Textcache.

## G. Pseudonymisierung und Risiken
Vor externem Versand strukturiertes Mapping von Nutzer-, Kurs-, Aktivitäts-, URL-, Zeit- und weiteren Identifikatoren in kurzlebige Tokens. Nicht nur Regex: Freitext, Kursnamen, Aufgabentitel, Feedback und Kontext können indirekt identifizieren und müssen ausgeschlossen oder kontrolliert redigiert werden. Exakte Fristen können durch relative, grob gerundete Kategorien ersetzt werden; lokal nach Rendern echte Angaben einfügen. Keine Garantie echter Anonymität. Core-AI-Logging und Provider-Retention prüfen; Datenschutz-Folgenabschätzung nach Risikoprüfung entscheiden. AI-User-Policy beachten.

## H. Zeit und Cache
Eventgetriebene Dirty-Marker plus geplante Reconciliation und zeitgesteuerte Übergänge; keine alleinige Abhängigkeit von Moodle-Events. `next_transition_at` ist Minimum aus Fristen, Cooldowns, Öffnungszeiten, Cache-Sicherheitsablauf. Fingerprint aus Nutzer+Scope+relevanten Faktversionen+Rechten+Persona+Regeln+Sprache. Doppelte Generierung durch Lock/Singleflight verhindern. Änderungen durch externe Plugins können nicht vollständig per Event erkannt werden: konservative TTL und periodische Rekonziliation.

## I. Datenhaltung (logische Entitäten)
`slc_rule`, `slc_template`, `slc_persona`, `slc_dialog`, `slc_dialog_question`, `slc_response`, `slc_state_version`, `slc_comment_cache`, `slc_scope`, `slc_audit_event`. Tabellenbezeichnungen vor Implementierung auf Moodle-Längen-/Namenskonventionen prüfen. Antworten getrennt von generierten Kommentaren speichern, Retention getrennt festlegen. Keine Duplizierung von Core-Grades/Completion als Schatten-Datenbank; nur abgeleitete kurzlebige Zustände.

## J. Rollen und Schnittstellen
Admin: globale Policies, freigegebene Persona-Prompts, KI-/Kursverbundfreigabe, Limits. Lehrende: Instanz/Scope/Regeln/Interviewvorlagen. Lernende: Anzeigen, Antworten, Überspringen, gegebenenfalls Persona-Präferenz. Services: `get_commentary`, `submit_response`, `dismiss_interview`, `get_available_actions`, `preview_rule`, `validate_import`. Alle Services verlangen sesskey/Capabilities/Context und serverseitige Zielprüfung; keine Autorisierung über Client-IDs.

## K. Import/Export und Backup
Versioniertes JSON-Konfigurationspaket mit wählbaren Elementen und referenzierten logischen IDs; Import als Dry-Run, Validierung und atomare Anwendung. Kurs-/Aktivitätsreferenzen über Restore-Mapping, keine Blindübernahme fremder IDs. Separates Moodle Backup/Restore, Privacy-Provider (Export/Delete) und Uninstall-Verhalten.

## L. Testarchitektur und Freigabegates
PHPUnit: Zustandskombinationen, Regeln, Scope, Zeiten, Rechte, Cache, AI-Policy, Platzhalter, Import/Export, Datenschutz. Adapter-Unit- und Integrationstests. Frontend-Komponententests (Jest/React-Testumgebung entsprechend Moodle 5.3), Behat: Instanz anlegen, Events, Abschluss/Grade/Frist, Sprache, Persona, Dialog, Skip, Cross-Course, AI-Ausfall, A11y-Smoke. Zusätzlich Property-/Mutationstests für Regeln, Concurrency, Lasttests, Security/Prompt-Injection. Tests mit Mock-AI-Provider reproduzierbar, echte Provider optional in gesonderter Testumgebung. Keine Placebo-Grüns: Versions-/Run-/Skip-Nachweis.

## M. Umsetzungsetappen
M0: API-Spike Moodle 5.3 React, AI-Integration, Subplugin-Registrierung; Privacy-Threat-Model.
M1: Core-only, statische Kommentare, Zustandsmodell, Scope, Tests.
M2: Regel-/Template-Editor, Cache/Zeiten, Empfehlungen.
M3: Dialoge und Persona-Prompts, KI mit Policy und Fallback.
M4: Adapter, Kursverbünde, Export/Import, Backup/Privacy.
M5: Performance-/Security-/Accessibility-/CI-Abnahme.

## N. Architekturentscheidungen vor Entwicklungsstart
ADR-01 Soll KI nur bei expliziter Nutzereinwilligung oder nach Moodle-AI-Policy-Annahme aktiviert werden? Juristisch prüfen.
ADR-02 Dürfen Lehrende einzelne Dialogantworten sehen oder nur aggregierte Daten?
ADR-03 Welche Kursverbund-Autorität und Rechteprüfung gilt bei kursübergreifenden Empfehlungen?
ADR-04 Wie wird „letzte Nutzung“ pro Modul belastbar definiert?
ADR-05 Sind Custom-Personas durch Lehrende selbst freigebbar oder nur durch Admins?
ADR-06 Welche Core-AI-Action-Logs entstehen und wie werden sie gelöscht?
ADR-07 Welche Default-Retention gilt für Freitextantworten?
ADR-08 Welche harte AI-Kostenobergrenze und Fair-Use-Policy gilt?

## O. Quellenbasis
Moodle 5.3 AI Plugins: https://moodledev.io/docs/5.3/apis/plugintypes/ai
Moodle 5.3 React Autoinit: https://moodledev.io/docs/5.3/guides/javascript/react/reactautoinit
Moodle 5.2 Frontend: https://moodledev.io/docs/5.2/guides/frontend
Moodle AI Subsystem: https://moodledev.io/docs/4.5/apis/subsystems/ai


## M. Verbindliche Architekturentscheidungen V1.1
**ADR-01 Datenschutz und Debugging:** Sideline-Dialoge, Antwortdaten und individuelle Empfehlungsfolgen sind für Lehrende vollständig gesperrt. Ein eigener administrativer Debug-Export ist streng berechtigungs-, zweck- und zeitgebunden; jeder Zugriff wird auditiert. Rohfakten nur soweit erforderlich und verfügbar, keine pauschale Vollprotokollierung. Exportfelder: Zustandsfakten, Regel-/Template-/Promptversionen, Interviewantworten, Entscheidungskette, KI-Ein-/Ausgabe und Fallback. Retention separat definieren.

**ADR-02 Personas:** Administrativ versionierte und freigegebene Persona-Registry. Lehrende wählen je Aktivität eine erlaubte Teilmenge und Default; Schalter `learner_can_choose_persona`. Individuelle Auswahl ist eine private Nutzerpräferenz, keine Lehrendeninformation. Widerruf einer Persona-Freigabe führt zur sicheren Ersatzpersona und Cache-Invalidierung. Sicherheits- und Faktenregeln sind nicht überschreibbar.

**ADR-03 Kursverbünde:** Lehrende konfigurieren Scope-Referenzen auf Kurse, Kurszweige, Abschnitte und Aktivitäten in der Sideline-Instanz. Standortfreigabe erforderlich. Jeder Quellkurs benötigt eine separat überprüfte Autorisierung; zur Laufzeit Nutzerzugang, Sichtbarkeit und Capability für jede Datenart prüfen. Keine indirekte Offenlegung fremder Kursdaten in Kommentaren.

**ADR-04 Moodle AI Policy und Aktivitätseinwilligung:** AI-Policy des Moodle-Subsystems unverändert respektieren. Optionales Consent-Gate vor *jeder* personenbezogenen Analyse/Ausgabe beim Erstzugriff; zulässig ist vorher nur die minimale Prüfung und Speicherung des Einwilligungsstatus. Zustandsmaschine: `not_requested`, `accepted`, `declined`, `withdrawn`, `reconsent_required`; versionierte Information, Zeit und Kontext speichern. Ablehnung: nur neutrale Consent-Ansicht, kein personenbezogener Kommentar. Widerruf: künftige Verarbeitung stoppen, Cache invalidieren, laufende Jobs soweit möglich abbrechen, Retention-/Löschregeln anwenden. Spätere Änderung der Entscheidung ermöglichen. Die Rechtsgrundlage und Textgestaltung bleiben datenschutzrechtlich zu prüfen.

**Privacy Provider:** Alle nutzerbezogenen Tabellen/Felder inventarisieren und Moodle Privacy API für Export/Delete und Kontextoperationen implementieren; Debug-Export ist davon getrennt. Consent-Nachweise nur nach definierter Rechtsgrundlage/Frist aufbewahren.

**Zusätzliche Testmatrix:** 1) Lehrendenzugriff auf Dialogdaten/API verweigert, 2) Admin-Debug-Export protokolliert und minimiert, 3) Persona-Entzug/Fallback, 4) Lernendenwahl und Cache, 5) Kursrechte zur Laufzeit entzogen, 6) vor Consent kein Profiling/AI-Aufruf, 7) Ablehnung/erneute Zustimmung/Widerruf, 8) Race Condition mit laufender AI-Generierung, 9) Privacy-Export/Delete/Context-Delete, 10) Moodle-AI-Policy nicht akzeptiert.

**Offene technische Verifikation:** Die konkreten Moodle-5.3-AI-Placement-/Policy-Hooks und Privacy-Provider-Interfaces sind gegen den Zielbranch zu prüfen. Keine Annahme einer automatisch wirksamen Einwilligungssperre allein durch Frontend-Rendering.
