# Sideline Learning Coach — Pflichtenheft v1.0
Stand: 24.10.2026 · Basis: Lastenheft v1.0

## 1. Verbindliche Systemgrenzen
`mod_sideline` ist ein Moodle-Aktivitätsmodul; React/TypeScript/ESM ist die Darstellung, PHP die alleinige fachliche Entscheidungsinstanz. KI darf ausschließlich sprachlich ausgestalten, keine Moodle-Zustände bestimmen. `slcmod_*`-Adapter liefern Fakten, keine ungeprüften HTML-Fragmente oder Prompt-Anweisungen.

## 2. Zustandsmodell
P-01 Pro betrachteter Aktivität sind mindestens die unabhängigen Dimensionen `visibility`, `availability`, `completion`, `grade`, `pass`, `attemptability`, `timewindow`, `lastactivity`, `dataquality` zu ermitteln. Unbekannte Werte werden explizit als unbekannt modelliert, niemals als negativ.
P-02 Fristen berücksichtigen Zugangsvoraussetzungen, modulspezifische Öffnungs-/Schließzeiten und Abschlussbedingungen getrennt; für Kursabschluss gelten die Moodle-Core-Bedingungen.
P-03 Bei widersprüchlichen Daten gelten Core-APIs als Autorität für Core-Zustände; Adapter dürfen nur spezifizierte modulinterne Ergänzungen liefern.
P-04 Ein Empfehlungskandidat enthält Aktion, Ziel, Voraussetzungen, Begründung, Priorität, zulässige Sichtbarkeit und Gültigkeit; vor Auslieferung werden Rechte und Zugänglichkeit erneut geprüft.

## 3. Regel- und Template-System
P-05 Regeln bestehen aus Scope, Trigger, booleschen Bedingungen, Priorität, Cooldown, maximaler Häufigkeit, Textstrategie und optionalem Interview.
P-06 Regelkollisionen sind deterministisch aufzulösen: Sicherheit/Fristen vor konkreten nächsten Schritten, danach Erfolge, Orientierung und Smalltalk; maximal eine Hauptbotschaft plus begrenzte Ergänzungen.
P-07 Templates besitzen Sprachversion, Variablenschema, Versionsnummer und HTML-sichere Ausgabe. Variablen werden serverseitig typisiert und escaped.
P-08 Fallback-Reihenfolge: gültiger KI-Text → lokalisierter Template-Text → sicherer generischer Text.
P-09 KI-Text darf Fakten nicht ergänzen; eine strukturelle und semantische Plausibilitätsprüfung gegen Fakten/erlaubte Aktionen ist vorzusehen. Bei Unsicherheit Fallback.

## 4. Persona-Engine
P-10 Systemprompt setzt sich aus unveränderlichen Sicherheits-/Faktenregeln, administrativer Persona-Definition, kursspezifischer Konfiguration, strukturierten Zustandsfakten und Ausgabeformat zusammen.
P-11 Sportreporter: ereignisbezogen, gelegentlich kritisch, interviewend; keine abwertenden Leistungszuschreibungen.
P-12 Ring-Coach: energisch, direkt, sportmetaphorisch; keine Beschimpfungen, Drohungen oder psychischer Druck.
P-13 Mentor: wertschätzend, ressourcen- und lösungsorientiert; systemische und transaktionsanalytisch inspirierte Fragen ohne Diagnose, Therapiebehauptungen oder Zuschreibung psychischer Zustände.
P-14 Custom-Personas: versioniert, Rollenrechte, Vorschau, Freigabe, Prüfregeln; dürfen globale Sicherheitsregeln nicht überschreiben.
P-15 Nutzende können eine angebotene Interviewfrage überspringen; Häufigkeit und Wiederholung sind begrenzt.

## 5. Dialoge
P-16 Unterstützte Typen: Single-/Multiple-Choice, Kurzfreitext, Slider, semantisches Differenzial (mehrere bipolare Skalen).
P-17 Antworten erhalten Typ, Zeit, Einwilligungs-/Rechtsgrundlagenkontext, Aufbewahrungsregel und optionale Zweckbindung; freie Antworten niemals automatisch an KI übermitteln.
P-18 Antworten dürfen nur ausdrücklich freigegebene, erklärbare Empfehlungen beeinflussen; keine verdeckten Persönlichkeitsprofile.
P-19 Antworten sind bearbeitbar/löschbar nach festgelegtem Konzept; keine Pflicht zur Beantwortung für Kursfortschritt.

## 6. KI, Sprache und Pseudonymisierung
P-20 KI-Integration über `core_ai\manager` und `generate_text`-Action, Provider-Auswahl ausschließlich über Moodle-AI-Konfiguration; keine direkten externen HTTP-Aufrufe.
P-21 Zu prüfen ist, ob ein eigenes `aiplacement_sideline` erforderlich ist, um Core-Policy, Berechtigungen und Reporting korrekt abzubilden; kein ungeprüfter direkter Manager-Bypass.
P-22 Vor Übergabe an den Provider: keine Klarnamen, User-/Kurs-/Modul-IDs, URLs, exakten Datums-/Zeitwerte, Mailadressen oder freien identifizierenden Inhalte. Stattdessen lokal erzeugte Platzhalter (`[anonuser1]`, `[anonmod1]`, `[anondate1]`). Relative Fristen nur in kontrolliert generalisierter Form.
P-23 Rückauflösung ausschließlich nach sicherer serverseitiger Validierung, mit kontextgebundener kurzlebiger Zuordnung. Pseudonymisierung ist keine garantierte Anonymisierung; Reidentifizierbarkeit über Kontext und Provider-Logs ist zu bewerten.
P-24 Generierung direkt in Nutzersprache bevorzugt; falls Übersetzung nötig, erfolgt sie als gesonderter geprüfter Textgenerierungsschritt mit unveränderten Platzhaltern. Unbekannte Sprache → lokalisierter Fallback.
P-25 KI nur bei Standortfreigabe und erfüllter AI-User-Policy; Ausfall, Timeout, Rate Limit oder Kostenlimit → statische Ausgabe.
P-26 Die Verarbeitung von KI-Aktionsprotokollen und Prompt-/Response-Speicherung durch Moodle-Core/Provider ist vor Freigabe technisch und datenschutzrechtlich zu prüfen.

## 7. Cache und Ereignisse
P-27 Cache-Key umfasst Nutzer, Instanz, Scope, Persona-/Regel-/Template-Version, Sprache, Zustandsfingerprint und Berechtigungs-/Konfigurationsversion.
P-28 Invalidierung bei Completion-, Grade-, Attempt-, Availability-, Kursstruktur-, Einstellungs-, Dialog- und Rollenänderungen; zeitabhängige Übergänge über berechnetes `next_transition_at` auch ohne Event.
P-29 Observer invalidieren/markieren nur; aufwendige Neuberechnungen erfolgen gebündelt und bei Bedarf. Race Conditions werden mit atomarer Versionskontrolle abgefangen.
P-30 Generierte Texte werden nicht erneut erzeugt, solange der relevante Zustand unverändert ist. Personalisierte Texte werden nie zwischen Nutzenden geteilt.

## 8. Konfiguration und Transfer
P-31 Administrator: KI an/aus, Kursverbünde an/aus, zulässige Personas, Promptfreigaben, Rate-/Kostenlimits, Retention, Interview-Policy.
P-32 Lehrende: Scope, Trigger, Regeln, Templates, Persona, Dialoge innerhalb globaler Grenzen.
P-33 Selektiver Export/Import mit Schema-Version, Validierung, Vorschau, Konfliktauflösung und Zielreferenz-Mapping. Keine Nutzerantworten, Tokens oder Cachetexte im Konfigurationsexport.
P-34 Moodle Backup/Restore und Privacy-Export/Delete gesondert spezifizieren und testen.

## 9. Abnahmekriterien und Tests
P-35 Jeder Zustandsübergang, jede Regel, jede Persona-Policy, jeder Dialogtyp, jedes Subplugin und jeder Berechtigungsfall hat Unit-/Integrationstests sowie geeignete Behat-End-to-End-Abdeckung.
P-36 Negativtests: unsichtbare Aktivitäten, nicht bestandene aber abgeschlossene Aktivität, abgelaufene Frist, zukünftige Freigabe, unbekannte Nutzungszeit, kursfremde Nutzer, AI-Policy fehlt, Provider-Ausfall, Prompt Injection, Cache-Rennen, Zeitumstellung, Sprachewechsel.
P-37 Matrix: Moodle 5.3 als Pflichtziel; PostgreSQL/MariaDB; PHP- und Browser-Versionen gemäß 5.3-Anforderungen; CI-Nachweis für ausgeführte Tests ohne Skips als Ersatz.
P-38 Accessibility: Tastatur, Screenreader, Fokus, Animation abschaltbar, `prefers-reduced-motion`, Statusmeldungen nicht aufdringlich.
P-39 Performance: definierte Budgets für DB-Abfragen, Antwortzeit, Cache-Hitrate und AI-Aufrufe vor Produktivfreigabe; Last-/Concurrency-Tests und Security-Tests ergänzen Unit/Behat.

## 10. Definition of Done
Fachlich dokumentierte Zustände und Entscheidungen; echte Testausführung; Privacy-/Security-/Accessibility-Prüfung; dokumentierte Provider-/AI-Policy-Kompatibilität; stabile Backup-/Restore- und Upgradepfade; keine kritischen offenen Befunde.


## 9. Ergänzende verbindliche Anforderungen V1.1
- **P-40 Sichtbarkeit:** Sideline bietet Lehrenden weder Endpunkte noch UI zur Einsicht in individuelle Antworten, Eingaben, KI-Texte, persönliche Zustandsprofile oder Empfehlungen. Alle Services prüfen Rechte und Datensubjekt.
- **P-41 Debug-Export:** Nur explizit berechtigte Standortadministration; begründeter, auditierter Zugriff mit Datenumfang, Zeitfenster und Nutzerbezug. Export enthält verfügbare Eingabefakten, Regel-/Promptversion, Antwort, Ausgabe, Konsequenzen und Fehler-/Fallbackstatus. Keine unbegrenzte Rohdatenspeicherung nur für hypothetisches Debugging.
- **P-42 Persona-Policy:** Nur freigegebene, versionierte Personas können aktiviert werden. Lehrende legen eine erlaubte Teilmenge und Default fest. Optional kann der Lernende seine Persona selbst auswählen und später wechseln; Auswahländerung invalidiert den individuellen Textcache. Entzug einer Freigabe wirkt auf bestehende Instanzen.
- **P-43 Kursverbünde:** Auswahl der Quellkurse/-zweige durch Lehrende innerhalb der Aktivität; globale Adminfreigabe als notwendige Bedingung. Autorisierung für jeden Quellkurs und jede Datenart bei Konfiguration und Auswertung erneut prüfen; Rechteentzug stoppt weitere Auswertung.
- **P-44 Moodle AI Policy:** Vor AI-Aufrufen müssen die einschlägigen Moodle-AI-Policy-/Capability-Prüfungen erfolgreich sein. Standortweit deaktivierte KI erzwingt den statischen Fallback.
- **P-45 Optionale Aktivitätseinwilligung:** Wenn konfiguriert, vor erster personenbezogener Analyse oder Ausgabe Information und Entscheidung anzeigen; bis zur Zustimmung keinerlei personenbezogene Zustandsberechnung, Textgenerierung oder Interviewauswertung. Ablehnung ermöglicht späteres erneutes Entscheiden ohne Nachteil außerhalb der Aktivität.
- **P-46 Protokollierung:** Entscheidung einschließlich Ablehnung, Zustimmung und Widerruf mit Nutzer, Aktivität, Zeit, Informations-/Policyversion und Status revisionsfähig erfassen; für die Entscheidung selbst nur notwendige Minimaldaten verarbeiten.
- **P-47 Änderung/Widerruf:** Jederzeit über die Aktivität möglich. Widerruf verhindert neue Verarbeitung, invalidiert Cache, storniert soweit möglich laufende Generierungen und behandelt gespeicherte personenbezogene Daten nach Lösch-/Aufbewahrungsregeln.
- **P-48 Privacy API:** Export, Löschung, Kontextlöschung und Nutzerauflistung für alle personenbezogenen Sideline-Datentypen einschließlich Consent, Antworten, Cache, Debug-Logs und Persona-Präferenzen; referenzierte Core-Daten nicht unberechtigt löschen.
- **P-49 Testabnahme:** Negative Rechteprüfungen für Lehrende, Debug-Export-Berechtigungen, Persona-Freigabeentzug, Kursverbund-Rechteentzug, Einwilligung vor Analyse, Ablehnung, Widerruf, laufende AI-Aufträge, Privacy-Export/Delete und Backup/Restore sind automatisiert nachzuweisen.
