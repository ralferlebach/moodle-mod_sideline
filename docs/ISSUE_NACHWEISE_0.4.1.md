# Nachweise 0.4.1 zu den Issues #3 bis #27

Stand: 03.11.2026. Grundlage: Critical-Friend-Re-Audit von 0.4.0 (02.11.2026, Commit `af87491`) mit den GitHub-Läufen 41532802030 (Main-CI), 41532860755 (Playwright) und 41532897557 (k6).

Dieses Dokument ordnet jedem offenen Akzeptanzkriterium (AK) und DoD-Punkt die Ursache, die Korrektur, die Fundstelle und den ausgeführten Nachweis zu. Es ist zum Übertragen in die Issues gedacht. Die Issues verwaltet ausschließlich das Audit; aus dieser Sitzung heraus wurde kein Issue geändert.

**Keine Release-Gates:** Es gibt keinen neuen Job und keine neue Abhängigkeit von „CI complete“. Der Upgrade-Job hat nur weitere Matrixzellen bekommen (Ausgangsstand 0.3.2).

**Wo die Läufe herkommen:** Alle Läufe liefen lokal mit denselben Schritten und Optionen wie die Workflows (`tools/ci-local/`), und zwar auf dem **GitHub-Checkout** des entpackten Auslieferungs-ZIP: `tools/ci-local/checkout.sh` übernimmt die Dateien wie `git add -A` (mit `.gitignore`), klont neu und entfernt alle Ausführungsrechte, wie es beim Repository geschehen ist. Genau daran sind die GitHub-Jobs von 0.4.0 gescheitert, nicht an der Logik. GitHub-Run-IDs auf dem Commit von 0.4.1 entstehen erst nach dem Push und sind dann nachzutragen.

Legende: ✔ erfüllt und ausgeführt · ◐ technisch erfüllt, fachliche Entscheidung oder GitHub-Lauf steht aus · ✘ nicht erfüllt

## Gesamtergebnis der Abnahmeläufe

Alle Läufe vom 03.11.2026 im Container, auf dem GitHub-Checkout des entpackten ZIP:

| Prüfung | Ergebnis |
|---|---|
| CI-Matrix (moodle-plugin-ci 4.5.11, Schritte wie `moodle-plugin-ci-main.yml`): 4.5/8.2/pgsql, 4.5/8.3/mariadb, 5.0/8.2/pgsql, 5.0/8.3/mariadb, 5.1/8.3/pgsql, 5.2/8.3/mariadb, 5.3/8.3/pgsql, 5.3/8.3/mariadb | **8/8 Zellen**, je 14 Schritte: install, phplint, phpcpd, phpmd, codechecker, phpdoc, validate, savepoints, mustache, grunt, PHPUnit, Adapter-Suites, Races, Behat. Der Codechecker meldete im Abnahmelauf zwei Formfehler in `tests/behat/behat_mod_sideline.php` (mehrzeilige Aufrufe); behoben, Datei danach erneut mit Codechecker und Behat geprüft (siehe unten). |
| PHPUnit `mod_sideline_testsuite` (`--fail-on-warning --fail-on-skipped --fail-on-incomplete`) | **461/461**, 29 670 Assertions, 0 Skips, in jeder Zelle (vorher 421) |
| Adapter-Suites | grün in jeder Zelle |
| Races `tools/race/run.php` | 5/5 in jeder Zelle |
| Behat | **30/30 Szenarien, 433/433 Schritte** in jeder Zelle (vorher 17 und 225). Auf 4.5/pgsql scheiterte im ersten Durchgang ein Szenario von `course_union.feature` schon im Hintergrundschritt: Eine Kategorie aus dem vorigen Szenario war nach Moodles Zurücksetzen noch in der Datenbank („ID number is already used“). Die automatische Wiederholung von moodle-plugin-ci war grün, ebenso drei isolierte Läufe des Features (je 3/3). Ein Fehler im Plugin ist das nicht; offen bleibt, ob er auf GitHub wieder auftritt. |
| Coverage (pcov, wie der Coverage-Job) | **87,05 %** Zeilen dieses Plugins (3744/4301), Untergrenze 80 % |
| Frontend (`js-checks.sh`, aktueller GitHub-Stand von Moodle 5.3 `236a9d7`, Checkout ohne Ausführungsrechte, Werkzeugkette frisch aus der Lockdatei) | ESLint 0 (inklusive Playwright), `eslint:react` 0, `tsc` strict 0, Jest React **23/23**, AMD **19/19** (beide mit axe), ESM- und AMD-Bundle aktuell; AMD-Bundle auf 4.5 und 5.3 byte-gleich |
| Upgrade (`upgrade.sh`, wie der Job) | **4/4 Pfade:** 0.4.0 → 0.4.1 und 0.3.2 → 0.4.1, je auf 4.5/pgsql und 5.3/mariadb. Schema ohne Abweichung, Datenprüfung 9/9 (von 0.4.0) bzw. 11/11 (von 0.3.2) |
| Release-Artefakt (`git archive` mit `.gitattributes`) | keine Entwicklerwerkzeuge, alle Pflichtinhalte, darunter `db/caches.php` |
| Veraltete Dateien | kein Pfad aus `db/removed_files.txt` vorhanden |
| Playwright (`playwright.sh`, frische Installation wie der Workflow, `retries: 0`) | Moodle 5.3 (React): **3 volle Läufe je 10/10**, danach die Gruppen-Journey **10-mal hintereinander** auf einem Kurs grün. Moodle 4.5 (AMD): ebenso **3 × 10/10** und **10/10**. Vor der Korrektur der Zielgröße und eines Wartefehlers im neuen Test scheiterten die A11y-Prüfungen; die sechs bisherigen Journeys waren in allen Läufen grün. |
| Gruppenpfad (`tools/bench_groups.php`, 100/1000/5000 Personen, 0 bis 200 Gruppen) | PostgreSQL 12/12 im Budget: warm 8 Lesezugriffe (Grenze 12), kalt höchstens 24 ms. MariaDB 12/12 im Budget, warm 6 Lesezugriffe, kalt höchstens 44 ms; im ersten Lauf direkt nach dem Leeren aller Caches einmal 575 ms bei 100 Personen ohne Gruppen, in zwei Wiederholungen 5 bis 6 ms |
| Namensprüfung (`tools/bench_names.php`, Worst-Case-Text) | 100 bis 20 000 Einschreibungen: je **2 Abfragen**, unter 1 KiB Speicher; bei 20 000 PostgreSQL 129 ms, MariaDB 104 ms (Budget 200 ms) |
| k6 | in 0.4.1 nicht erneut gelaufen; der Gruppenpfad ist über `bench_groups.php` gemessen |

Unterschiede zu GitHub: ChromeDriver unter Xvfb statt Selenium-Container; PostgreSQL 17 und MariaDB 11.4 als lokale Server statt als Dienst-Container.

---

## Phase 1: CI und ausgefallene Tests

### #20 Frontend-CI

| AK / DoD | Status | Ursache, Korrektur, Nachweis |
|---|---|---|
| Ursache | ✔ | Im Repository hat keine Datei das Ausführungsrecht (`git ls-files -s`: kein Eintrag mit Modus 100755). Der Workflow rief `plugin/tools/ci-local/js-checks.sh` direkt auf, daher „Permission denied“ und Exit 126. Lokal fiel das nicht auf, weil die Arbeitskopie die Rechte hatte. |
| Korrektur | ✔ | Alle Skripte werden mit `bash` aufgerufen: Workflow (`moodle-plugin-ci-main.yml`, Frontend-Job), `makefile` und die Skripte untereinander (`upgrade.sh` → `ci.sh`). |
| TS strict, Jest React und AMD, axe, ESM- und AMD-Bundle wirklich ausgeführt | ✔ lokal, ◐ GitHub | Nachbildung des Jobs: frischer Moodle-5.3-Klon vom aktuellen GitHub-Stand (`MOODLE_REPO=github`), Checkout ohne Ausführungsrechte, leeres `MPCI`, also frisch installierte Werkzeugkette. Ergebnis siehe Gesamtergebnis. |
| Reproduzierbarkeit | ✔ | Die Jest-Werkzeugkette des AMD-Panels war ungepinnt (`jest@30` usw.). Jetzt ist sie durch `tools/ci-local/jest-amd/package-lock.json` festgelegt und wird mit `npm ci` installiert. |
| Log und SHA | ◐ | nach dem Push: Run-ID des Frontend-Jobs |

### #21 Upgrade-CI

| AK / DoD | Status | Ursache, Korrektur, Nachweis |
|---|---|---|
| Ursache | ✔ | `.gitignore` enthielt `ci/` ohne führenden Schrägstrich. Das Muster gilt in jedem Verzeichnis und hat `tools/ci/upgrade_seed.php` und `upgrade_verify.php` vor Git versteckt (Vergleich des geklonten Repositorys mit 0.4.0: genau diese zwei Dateien fehlten, alle anderen waren identisch). |
| Korrektur | ✔ | `/ci/` und `/moodle/` verankert. `checkout.sh` meldet künftig jede Datei, die `.gitignore` ausschließt; Gegenprobe mit der alten `.gitignore`: meldet genau die zwei Dateien. |
| Richtige Altversion | ✔ | Matrix mit `from: previous` (letzter Commit mit anderem Release-String, jetzt 0.4.0) und `from: '0.3.2'` (letzter Stand vor den Schemaänderungen von 0.4.0), je auf 4.5/pgsql und 5.3/mariadb. Die Release-Suche ist gegen die echte Historie geprüft (`previous` → `092a7c1` für HEAD 0.4.0; `0.3.2` → `092a7c1`; nicht vorhandenes Release → Fehler). |
| Daten und Prüfung je Ausgangsstand | ✔ | `upgrade_seed.php` legt Daten wie vor 0.4.0 an (Quelle ohne Lehrperson, Mindestgröße 2, KI-Aufruf ohne Ende) oder wie ab 0.4.0 (bestätigte Quelle, Ereignisarten, abgeschlossener KI-Aufruf). `upgrade_verify.php` prüft die Upgrade-Schritte bzw. die Unverändertheit, dazu Version, Schema, Kommentar nach dem Upgrade und kein veralteter Cache-Text. |
| Upgrade 4.5/PG und 5.3/MariaDB mit Schema-Diff und Daten | ✔ lokal, ◐ GitHub | siehe Gesamtergebnis |

### #22 Playwright-Gruppenjourney

| AK / DoD | Status | Ursache, Korrektur, Nachweis |
|---|---|---|
| Login-, Session- oder Fixture-Race gegen Fachfehler abgegrenzt | ✔ | **Kein Fachfehler.** Erster Versuch: Der Test löschte die Cookies, während `view.php` mit laufendem JavaScript offen war. Die nächste AJAX-Antwort der Seite leitete zum Login um und brach das `page.goto('/login/index.php')` des Tests ab (`net::ERR_ABORTED`, `helpers.js:45` über `sideline.spec.js:136`). Wiederholung: scheiterte nur, weil der erste Versuch das Teilen von Student2 schon abgeschaltet hatte; die Journey war nicht wiederholbar. Der fachliche Teil (Aussage sichtbar, nach dem Abschalten weg) hatte im ersten Versuch bereits bestanden. |
| Korrektur ohne Retry-Tricks | ✔ | Jede Person bekommt einen eigenen Browserkontext (`browser.newContext()`), es werden keine Cookies unter einer laufenden Seite gelöscht. Die Journey stellt ihren Ausgangszustand selbst her. `retries: 0` in `playwright.config.js`. |
| Wiederholt vollständig grün | ✔ lokal, ◐ GitHub | `tools/ci-local/playwright.sh` (Nachbildung des Workflows): drei volle Läufe je Moodle-Version und die Gruppen-Journey zehnmal hintereinander auf demselben Kurs, siehe Gesamtergebnis. Auf GitHub: Workflow „Browser journeys (Playwright)“ manuell auf dem 0.4.1-Commit starten (bleibt auf Wunsch manuell). |

### #15 CI- und E2E-Gesamtabdeckung

| AK / DoD | Status | Nachweis |
|---|---|---|
| Frontend, Upgrade, Playwright auf aktuellem SHA | ✔ lokal, ◐ GitHub | Ursachen behoben (#20, #21, #22); lokal auf dem GitHub-Checkout grün, siehe Gesamtergebnis |
| Browserpfad | ✔ | Behat jetzt 30 Szenarien (vorher 17), dazu Playwright 10 Journeys; neu: Kursverbund, Downloads im HTTP-Pfad, Import, Backup und Restore, Sprachen, A11y |
| Adapter, Races, Upgrade-Belege | ✔ | in jeder Matrixzelle bzw. im Upgrade-Job |
| Playwright im Push-CI | ◐ | bleibt auf ausdrücklichen Wunsch des Auftraggebers manuell (`workflow_dispatch`). Das widerspricht dem AK; die Entscheidung liegt beim Auftraggeber. |

---

## Phase 2: Datenschutz, Autorisierung

### #3 Widerruf und #4 KI-Prompt-Datenschutz: separates Security-/Privacy-Review

| AK / DoD | Status | Nachweis |
|---|---|---|
| Separates Review dokumentiert | ✔ | `docs/security/REVIEW_0.4.1.md` (Zusammenfassung und Umsetzung) und `review_0.4.1_report_en.md` (Originalbericht). Unabhängiger Agent ohne Kenntnis der Entwicklungsschlussfolgerungen, nur Lesezugriff, jede Kernaussage am Quelltext geprüft. 12 Befunde, keiner kritisch oder hoch, alle umgesetzt und getestet. |
| #3: was nach einem Widerruf bleibt | ✔ | F3/F4: Traces, Regeltreffer sowie Prompt und Antwort im KI-Kern werden entfernt; Prüfung nach dem letzten Schreibvorgang. Tests `delivery_atomicity_test` (zwei neue), `ai_integration_test`. |
| #4: verbleibende Identifikatoren | ✔ | Inventar der Prompt-Felder in `docs/privacy/DATENSCHUTZ.md`, Abschnitt 2. Neu gefunden und geschlossen: Regelschlüssel (F1), Persona-Stile (F5), Namen aus Kursverbünden (F10), Namen in Antworten (F11), weitere Datums-, URL- und Injection-Formen (F6). Benannt, aber nicht im Plugin lösbar: die verkettbare Nutzerkennung `user`, die der Moodle-Kern an den Anbieter sendet. |
| #4: Teilnahme für Lehrende sichtbar | ✔ | F2: Der KI-Nutzungsbericht des Kurses zeigte, wer den Coach nutzt. KI-Aufrufe laufen jetzt im Nutzerkontext. |
| Datenschutzentscheidungen | ◐ | D1 bis D5 in `DATENSCHUTZ.md`, außerhalb der CI und kein Gate |

### #5 Legacy-Kursquellen und #16 Cross-Course-Cache

| AK / DoD | Status | Nachweis |
|---|---|---|
| Mehrkursiges Behat (#5 DoD2, #16) | ✔ | `tests/behat/course_union.feature`, 3 Szenarien: Abschluss in einem weiteren Kurs aktualisiert den Kommentar des Heimatkurses; Abmeldung der Lehrperson aus dem weiteren Kurs beendet dessen Auswertung sofort; eine Quelle ohne Lehrperson zählt erst nach der Bestätigung im Formular; ein Kurs eines Kursbereichs zählt, solange er im Bereich liegt und die Rolle der Lehrperson im Bereich besteht (Verschiebung hinaus und zurück, Rollenentzug). |
| Dabei gefunden | ✔ | (1) Das Einstellungsformular ließ sich bei einer Pause von 0 Sekunden (oder keinem Vielfachen einer Stunde) nicht öffnen (Coding Error des Dauerfelds). Korrigiert, Test `mod_form_test` mit Gegenprobe. (2) Der Hinweis auf unbestätigte Quellen stand in einem eingeklappten Abschnitt; der Abschnitt wird jetzt aufgeklappt. |
| Upgrade-Evidenz (#5) | ✔ lokal, ◐ GitHub | Upgrade von 0.3.2: Legacy-Quelle bleibt zur Bestätigung erhalten und wird nicht ausgewertet; von 0.4.0: bestätigte Quelle bleibt bestätigt und wird ausgewertet |

### #6 Gruppenereignisse

| AK / DoD | Status | Nachweis |
|---|---|---|
| Ereignismatrix | ◐ | unverändert umgesetzt (`GRUPPENAGGREGATE.md`, Abschnitt 1). Die eingeschränkte Semantik ist eine **offene Produktentscheidung** mit Auftraggeber und DSB (D4) und ist nicht als vollständige Umsetzung des ursprünglichen AK gemeint. |

### #12 Gruppen-Reidentifikation

| AK / DoD | Status | Nachweis |
|---|---|---|
| Restrisiken geklärt, technisch | ✔ | Zwei konkrete Differenzwege aus dem Review geschlossen: Tagesstabilität ließ sich durch neu abgestempelte Erledigungen unterlaufen (F7, Tagesschnappschuss); bei genau drei anderen Mitgliedern beschrieb „einige“ jedes einzelne (F12, keine Aussage über alle anderen Mitglieder). Tests in `group_privacy_test`. Tabelle der Restrisiken in `GRUPPENAGGREGATE.md`, Abschnitt 3. |
| Fachliche Bewertung | ◐ | D4, außerhalb der CI |
| Kosten | ✔ | `tools/bench_groups.php` nach der Änderung, siehe Gesamtergebnis |

### #14 Privacy und Core-AI-Logs

| AK / DoD | Status | Nachweis |
|---|---|---|
| Zielsystem-Retention | ✔ geprüft, ◐ Entscheidung | Quelltext Moodle 4.5 und 5.3: Es gibt weder eine Aufgabe noch eine Einstellung, die `ai_action_register` oder `ai_action_generate_text` von selbst löscht. Prompt und Antwort bleiben bis zu einer Datenschutzanfrage. Das Plugin leert sie jetzt beim Widerruf (F4). Frist und Umsetzung auf dem Zielsystem: D3. |
| Privacy-API vollständig | ✔ | F9: Autor-IDs in Kursquellen, Kursbereichen und Site-Personas werden beschrieben, exportiert und gelöscht. Test `provider_test` (neu). |
| Rechtsgrundlage, DSFA, DSB | ◐ | D1, D2, D5 |

---

## Phase 3: Codequalität und Zusatztests

### #10 Konfigurationstransfer und #19 Export-HTTP-E2E

| AK / DoD | Status | Nachweis |
|---|---|---|
| Download aus `transfer.php` im echten HTTP-Pfad | ✔ | `transfer.feature`: Klick auf „Export configuration“, Prüfung der Antwort-Header (Content-Type `application/json`, `Content-Disposition: attachment; filename="sideline-config-<cmid>.json"`, `Cache-Control` privat) und des Inhalts (Format, Regel, Frage; weder Name noch Benutzername noch Zustimmung einer lernenden Person) |
| Download aus `debug.php` | ✔ | echter POST des Formulars mit der Browsersitzung: JSON-Datei `sideline-debug-<cmid>-<userid>.json` mit dem angegebenen Zweck, ohne Klarnamen |
| Negativautorisierung | ✔ | Lernende: Transfer-Export auch mit gültigem Sesskey abgelehnt (Fehlerseite, kein JSON). Ohne Sesskey abgelehnt. Lehrende: Debug-Export abgelehnt. |
| Mehrkurs- und Cross-Site-Import | ✔ | Export aus Kurs 1, Upload in Kurs 2 über den Dateiwähler, Übernahme; die Lernenden werden in Kurs 2 neu gefragt (Zustimmung wird nie übertragen), Regel und Frage wirken. Datei einer fremden Site (`tests/fixtures/foreign_site_export.json`, unbekannte IDs, Kurs, Gruppe und Aktivität): Vorschau mit Warnungen, ohne Bestätigung wird nichts übernommen. |
| Restore-E2E | ✔ | Backup von Kurs 1 mit Nutzerdaten, Restore in einen neuen Kurs: Regel und Frage sind da, aber weder Zustimmung noch Antwort der lernenden Person (die Person wird neu gefragt, die Frage erscheint wieder). Dazu prüft die Wiederherstellung jetzt jede Regeldefinition. |

### #17 Sprachen und Fallback

| AK / DoD | Status | Nachweis |
|---|---|---|
| Lokalisierte Browser-E2E | ✔ | `languages.feature`: Deutsch (Texte des Plugins, KI-Text direkt auf Deutsch, kein Hinweis, ein Prompt); Französisch (englische Antwort, Übersetzungsschritt, Hinweis „übersetzt und geprüft“, zwei Prompts); Polnisch (keine Prüfregeln: englische Vorlage mit Fallback-Hinweis, kein Prompt). Rechts nach links in `a11y.spec.js`. |
| Dabei gefunden | ✔ | Ein installiertes Sprachpaket ohne Texte des Plugins (Normalfall bei Paketen vom Sprachserver) lieferte englischen Text ohne Fallback-Hinweis. Korrigiert (`message_composer::text_language()`), PHPUnit für Paket ohne Plugin-Texte, lokale Anpassung und Kindsprache. |

### #23 CSS-Lizenzkopf

| AK / DoD | Status | Nachweis |
|---|---|---|
| Vollständiger GPL-Kopf | ✔ | `styles.css`; dazu `tests/js/jest.config.js` und `tests/js/mocks/ajax.js`. Alle PHP-, JS-, TS- und CSS-Dateien haben jetzt den Kopf (Prüfung über alle Dateien; ausgenommen generierte Builds und `.feature`-Dateien, die in Moodle keinen Kopf tragen). stylelint über `grunt` grün. |

### #24 Prompt-Namen-Skalierung

| AK / DoD | Status | Nachweis |
|---|---|---|
| Keine unbegrenzte Namensliste im Speicher | ✔ | `prompt_text_guard::course_name()`: Die Wortfolgen des Texts (1 bis 4 Wörter) werden in der Datenbank mit Vor- und Nachnamen der Eingeschriebenen abgeglichen. Höchstens 500 Zeichen, also höchstens zwei Abfragen. |
| Query-, RAM- und Latenzbudget | ✔ | `tools/bench_names.php`, Worst-Case-Text, Budget 3 Abfragen, 200 ms, 256 KiB; Ergebnisse siehe Gesamtergebnis |
| Fail-closed | ✔ | Datenbankfehler → `budget`, nichts wird gesendet (Test mit simuliertem Fehler); Überlänge → `length` |
| Tests | ✔ | `prompt_guard_test`: Mehrwortnamen, Groß- und Kleinschreibung, Bindestrich, Apostroph, inaktive Einschreibung, anderer Kurs, gleiche Abfragezahl bei 5 und 400 Personen, Speicher, Fehlerfall |

### #25 Manueller A11y-Smoke

| AK / DoD | Status | Nachweis |
|---|---|---|
| Automatisierbare Teile im realen Pfad | ✔ | `a11y.spec.js` (React 5.3 und AMD 4.5): 400 % und 200 % Zoom (Reflow, Zielgrößen, sichtbarer Fokus, axe), RTL, Touch-Gerät |
| NVDA, VoiceOver, echter Zoom, Kontrastmodus | ✘ | nur von Hand möglich; Ablauf mit Ergebnistabelle in `docs/A11Y_SMOKE.md`, **noch nicht ausgeführt** |

### #26 Importgrößen und Tiefe

| AK / DoD | Status | Nachweis |
|---|---|---|
| Item- und Tiefenbudget | ✔ | `config_transfer::decode()`: 512 KiB, Tiefe 16, Texte bis 10 000 Zeichen, Höchstzahlen je Teil (`LIMITS`) |
| Malformed, Limit+1, keine stillen Verluste | ✔ | `config_transfer_test`: ungültiges JSON, zu tief, zu groß, zu langer Text, eine Regel/Aktivität/Quelle zu viel → ganze Datei abgelehnt; genau an jeder Grenze vollständig gelesen. Gefunden und korrigiert: Kursquellen über dem Maximum des Kursverbunds wurden beim Import stillschweigend verworfen; jetzt Ablehnung mit Meldung. |

### #27 Abhängigkeitssicherheit

| AK / DoD | Status | Nachweis |
|---|---|---|
| Enthaltene Fremdbibliotheken | ✔ | keine: React und `core/ajax` kommen von Moodle, die Builds enthalten nur eigenen Code (`docs/ABHAENGIGKEITEN.md`) |
| Bekannte Schwachstellen | ✔ | `npm audit`: Playwright-Lockdatei 0 Befunde; Jest-Werkzeugkette 3 transitive Advisories (deepmerge niedrig, glob und minimatch mittel), nur Testumgebung, bewertet und akzeptiert |

---

## Nicht technisch lösbar oder ausstehend

1. **GitHub-Läufe auf dem 0.4.1-Commit:** Main-CI (inklusive Frontend und vier Upgrade-Zellen) und ein manueller Start des Playwright-Workflows; die Run-IDs gehören in #15, #20, #21 und #22.
2. **DSB-Entscheidungen D1 bis D5** (`docs/privacy/DATENSCHUTZ.md`): betreffen #4, #6, #12 und #14 fachlich, nicht die CI.
3. **Manueller Screenreader-Test** (#25), Ablauf in `docs/A11Y_SMOKE.md`.
4. **Playwright im Push-CI** (#15): bleibt auf Wunsch manuell.
