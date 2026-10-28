# Audit 0.2.0 – Bearbeitungsstand

Bezug: `Sideline_0.2.0_Nacharbeitsauftrag_aktualisiert.md` (statische Prüfung von 0.1.0) einschließlich Gruppenmodus V1.2.
Stand: Version 0.3.0 (Build 2026102800, MATURITY_BETA), unterstützt Moodle 4.5 bis 5.3. Einzelnachweise je Anforderung: `docs/TRACEABILITY.md`. Die in 0.2.x verbliebenen Lücken sind in der Spalte rechts mit dem Stand von 0.3.0 vermerkt.

**Ausgeführt im Container:** PHPUnit 172/172 auf PostgreSQL 17.11 und MariaDB 11.4.13 · Behat 11/11 (4 mit Headless-Chrome 153,
2 davon mit axe-WCAG-Prüfung) · Jest 20/20 aus dem Moodle-Root (inkl. axe) · Moodles ESM-Build · phpcs 0 Fehler/0 Warnungen ·
Upgrade 0.1.0 → 0.2.0 gegen eine echte 0.1.0-Datenbank, Schemaabgleich ohne Abweichung · Last-/Cache-Messung.
**In 0.3.0 zusätzlich ausgeführt:** k6-Lasttest über HTTP, PHP 8.1 und 8.2 zur Laufzeit, Restore auf einer fremden Website, Coverage-Messung (89,4 %), Behat 13/13 und PHPUnit 305/305 auf allen fünf Versionen.
**Nicht ausgeführt:** GitHub-CI-Lauf, `local_moodlecheck` (Installation in den Moodle-Baum hier nicht erlaubt; läuft in der CI), ein echter KI-Provider (nur mit gemocktem HTTP).

| ID | Status | Was umgesetzt ist | Verbleibende Lücke |
|---|---|---|---|
| P0-01 Zugriff | ✔ | `require_login` in allen 7 Endpunkten, Scope ohne versteckte/Stealth-Aktivitäten, Linkprüfung vor jeder Auslieferung, Fingerprint über die Empfehlungen selbst | – |
| P0-02 Einwilligung/Race | ✔ | Recheck unter derselben Sperre vor Speichern und Auslieferung, versionierte Informationsstände, Protokoll mit Art, Aufbewahrung konfigurierbar | Informationstexte durch Datenschutz prüfen |
| P0-03 KI | ✔ / ◐ | ADR-AI-01, Capability `useai`, Integrationstest mit echtem Core-Provider und gemocktem HTTP (Body und Core-Tabellen ohne Klarnamen), Prompt-Injection-Tests | 0.3.0: semantische Aussagenprüfung und Sprachprüfung ergänzt; Core-Protokolle technisch geprüft (ADR-AI-02). Offen: rechtliche Prüfung |
| P1-04 Zustandsmodell | ✔ | Nutzung aus dem Log, Verfügbarkeitsbaum mit AND/OR/NOT, Sperrgrund, Versuche, Abgaben, Modulfristen nach Overrides, Kursabschluss, Zeitzonen | – |
| P1-05 Kursverbünde/Subsektionen | ✔ | rekursive Subsektionen, Kursverbund mit Standortschalter, Rechte je Quellkurs (Lehrende beim Speichern, Lernende je Anfrage) | 0.3.0: Kurszweige als Quelle umgesetzt |
| P1-06 Subplugins | ✔ | `slcmod`-Typ, Vertrag, Registry mit Typprüfung und Fallback, `slcmod_assign`, `slcmod_quiz` | weitere Module |
| P1-07 Personas | ✔ / ◐ | Admin-Personas mit Versionen, Freigabe, Widerruf, Teilmenge, Lernendenwahl, adversarial getestet | 0.3.0: Persona-Vorschau umgesetzt |
| P1-08 Dialog-Engine | ✔ | vier Typen, Trigger, Cooldown, Überspringen, Bearbeiten/Löschen, geschlossene Effektliste, Freitext nie an KI | – |
| P1-09 Regeln/Transfer | ✔ | Regelschema, Cooldown, Wochenlimit, Vorschau mit Beispieldaten, selektiver Transfer mit Mapping, Konflikten, Migration | – |
| P1-10 Debug-Export/Privacy | ✔ | nur Standortadmins, Zweckpflicht, Audit-Log, Tracing mit Ablauf, Retention, Privacy für alle 9 Tabellen | – |
| P1-11 Inline-Anzeige | ✔ | Anzeige im Kursabschnitt ohne Link, mehrere Instanzen unabhängig, Gate auch inline | – |
| P1-12 Backup/Restore/Upgrade | ✔ | Konfiguration, Scope, Gruppen, Regeln, Fragen; Upgrade-Pfad mit echten Daten | 0.3.0: Restore einer 4.5-Sicherung auf einer anderen 5.3-Website geprüft |
| P1-13 Cache/Performance | ✔ | Compare-and-Swap über `stamp`, Ereignisse für Rollen, Einschreibung, Gruppen, Versuche, Abgaben; Budgets gemessen | 0.3.0: k6-Lasttest über HTTP ausgeführt |
| P2-14 Barrierefreiheit/i18n | ✔ | native Controls, Fokusführung, aria, reduzierte Bewegung, axe in Jest und Behat, keine harten UI-Strings | manuelle Screenreader-Prüfung |
| P0-15 Test- und CI-Evidenz | ◐ | Matrix, Testläufe auf beiden Datenbanken, Behat im Browser | GitHub-CI-Lauf steht aus |
| Gruppenmodus G1–G9 | ✔ | Modi, Opt-ins, Stufen, Sichtbarkeitsregeln, Aggregation mit Mindestgröße, Recheck, KI nur Tokens, UI, Privacy | rechtliche Prüfung der Einwilligungsstufen |

## Unabhängiger Review (zweiter Prüfer ohne Kenntnis der Umsetzungsentscheidungen)
Acht Befunde, alle behoben, jeder mit Regressionstest in `tests/review_findings_test.php`:
1. **Hoch:** Lehrende mit Leserecht erhielten Gruppenaussagen und konnten so Freigaben ablesen. Jetzt nur mit `mod/sideline:receivegroupstatements` (nur Rolle Studierende), nie mit `manage`, und nur als Mitglied einer zugelassenen Gruppe.
2. **Hoch:** Der Konfigurationsimport erweiterte Freigabestufe und Einwilligungspflicht ohne neue Zustimmung. Jetzt gilt für Formular und Import dieselbe Versionslogik (`instance_config::apply_version_bumps`).
3. Aktivitäten in versteckten oder ganz verborgenen Abschnitten (Stealth) konnten erscheinen. Jetzt `is_stealth()` und Abschnittsprüfung.
4. Versteckte Verfügbarkeitsbedingungen (`showc`/`show`) konnten durch Öffnungszeit oder Sperrgrund verraten werden. Jetzt keine Erklärung bei versteckten Bedingungen.
5. Ein Widerruf der Einwilligung beendete die Gruppenfreigabe nicht. Jetzt endet sie mit, und Freigaben ohne Einwilligung zählen nicht.
6. Abschalten der Einwilligungspflicht hob ein „Nein“ der Lernenden auf. Jetzt bleibt es bestehen.
7. Nach Typwechsel einer Frage konnten alte Freitextantworten als Option gewertet werden. Jetzt werden Antworten bei Typ- oder Konfigurationsänderung gelöscht.
8. Wettlauf zwischen Freigabe-Widerruf und Speichern eines Kommentars mit Namen. Jetzt serialisiert eine Instanzsperre beide Schritte.

## Durch die neuen Tests gefundene und behobene Fehler
- `availability_analyser`: `xor` bindet in PHP schwächer als `=`; negierte Bedingungen wurden falsch ausgewertet.
- `slcmod_assign`: Sortierung von NULL-Werten unterscheidet sich je Datenbank; Gruppen-Overrides konnten Nutzer-Overrides verdrängen.
- `persona_form`: Das Formular verlor beim Absenden den Aktionsparameter (nur im Browser sichtbar, durch Behat gefunden).
- Core überschreibt ein Modulfeld namens `groupmode`; das Feld heißt deshalb `sharingmode`.
- Das Panel bot keinen Widerruf der Einwilligung an, obwohl der String existierte.

## Release-Gate 0.2.0
- [x] P0-Befunde behoben und reproduzierbar getestet
- [x] Abdeckungsmatrix (`docs/TRACEABILITY.md`), offene Punkte benannt
- [~] Moodle 4.5, 5.0, 5.1, 5.2 und 5.3 mit PostgreSQL, 4.5 und 5.3 zusätzlich mit MariaDB lokal getestet; GitHub-CI und echter KI-Provider stehen aus
- [x] Privacy API, Widerruf, Rollenzugriff, Debug-Export, Datenminimierung
- [x] Budgets gemessen, Parallelität, Barrierefreiheit, Backup/Restore, Upgrade
- [ ] Commit und CI-Run dokumentieren (beim Push durch den Auftraggeber)

## Unabhängiger Review 0.3.0
Ein zweiter Prüfer, der die Umsetzungsentscheidungen nicht kannte, hat die neuen Teile von 0.3.0 geprüft. Er meldete zwölf Befunde, alle sind behoben und getestet (Einzelheiten in `CHANGELOG.md`, 0.3.0). Die wichtigsten:
- **Mittel bis hoch:** Ein Block in anderer Sprache umging Sprach- und Aussagenprüfung.
- **Mittel:**
  - Erfolgsbehauptung nach Fehlschlag;
  - Platzhalter fremder Blöcke;
  - Kostenlimit im Fingerprint;
  - fehlende Neuprüfung der Lehrendenrechte bei Kursverbünden (P-43);
  - Lasttest prüfte das Race nicht.
- **Niedrig:**
  - Limit nicht atomar;
  - Domains, E-Mail-Adressen und Ziffern anderer Schriften;
  - Freigabe-Phrasen;
  - Wirkung nicht mehr erlaubter Fragetypen;
  - Sprachanpassungen nicht im Cache-Schlüssel;
  - Seed-Schutz.

Ohne Befund: Persona-Vorschau (Rechte, sesskey, Ausgabe), neue Tabellen und Upgrade, Template-Versionen, Datenschutz der Kurszweig-Auswertung.
