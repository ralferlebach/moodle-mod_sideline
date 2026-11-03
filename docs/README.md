# Entwicklerhinweise mod_sideline

Werkzeuge, Pipelines und Testinfrastruktur. Fachliche Dokumentation: `DOCUMENTATION.md`.

## Qualitätsprüfungen (`makefile`, `phpcs.xml`, `tools/`)
- `make check`: PHPCS mit dem Moodle-Standard, PHPDoc-Prüfer, Mustache, ESLint, PHPMD, PHPCPD, Build, Jest, PHPUnit.
- `make fix`: automatische Stil- und PHPDoc-Korrekturen.
- `make test-amd`: Jest-Tests des AMD-Panels (Moodle 4.5 bis 5.2).
- `tools/mirror.sh [MOODLE_ROOT]`: spiegelt die Arbeitskopie in einen Moodle-Baum. Erkennt das Layout mit und ohne `public/`.
- `tools/bench.php`: Messung von Cache und Erzeugung ohne HTTP.
- `tools/bench_groups.php`: Abfrageprofil des Gruppenpfads für 100/1000/5000 Personen und 0/10/50/200 Gruppen (Issue #7).
- `tools/bench_names.php`: Kosten der Namensprüfung von Freitexten für 100 bis 20000 Einschreibungen, Worst-Case-Text (Issue #24).
- `tools/race/run.php`: Rennen mit echten Parallelprozessen: KI-Kontingent, Widerruf und Invalidierung während einer Erzeugung (Issues #3, #8). Läuft in jeder CI-Zelle.
- `tools/ci/upgrade_seed.php`, `tools/ci/upgrade_verify.php`: Daten eines älteren Releases anlegen und nach dem Upgrade prüfen, abhängig vom Ausgangsrelease (CI-Job „upgrade“).
- `tools/ci-local/`: moodle-plugin-ci lokal mit denselben Schritten wie die CI (`setup.sh`, `ci.sh`), der GitHub-Checkout (`checkout.sh`), die Frontend-Prüfungen (`js-checks.sh`, Werkzeugkette in `jest-amd/`), der Upgrade-Job (`upgrade.sh`) und der Playwright-Workflow (`playwright.sh`). Immer mit `bash` aufrufen. Pflicht vor jeder Auslieferung, siehe `environment-setup.md`.
- `docs/privacy/`: Datenregister, KI-Protokolle, offene DSB-Entscheidungen; Gruppenaggregate und Ereignismatrix.
- `docs/SPRACHMATRIX.md`, `docs/A11Y_SMOKE.md`, `docs/ABHAENGIGKEITEN.md`: Sprachunterstützung; Barrierefreiheit (automatisch und von Hand); Abhängigkeiten und Advisories.
- `docs/ISSUE_NACHWEISE_0.4.0.md`, `docs/ISSUE_NACHWEISE_0.4.1.md`: Nachweise je Issue.
- `docs/security/REVIEW_0.4.1.md`: unabhängiges Security-/Privacy-Review.

## Pipelines (`.github/workflows/`)
- `moodle-plugin-ci-dev.yml`: schnelle Rückmeldung auf dem Entwicklungszweig (Moodle 4.5 und 5.3).
- `moodle-plugin-ci-main.yml`: volle Matrix Moodle 4.5 bis 5.3 mit PostgreSQL und MariaDB (mit Adapter-Suites, Races, Behat), Frontend-Job, Upgrade-Job von zwei Ausgangsreleases, Release-Artefakt, Coverage-Untergrenze, veraltete Dateien; „CI complete“ fasst diese Jobs nur zusammen. Kein Release-Gate, kein Versionsgleichlauf.
- `load-k6.yml`: HTTP-Lasttest mit k6, nur manuell (siehe `tests/load/README.md`).
- `playwright.yml`: Browser-Journeys in Chromium, nur manuell, Moodle-Version wählbar (siehe `tests/playwright/README.md`).

## Testinfrastruktur
- `tests/*.php`: PHPUnit (`mod_sideline_testsuite`). Die Adapter haben eigene Suites unter `adapter/*/tests`.
- `tests/behat/`: Ende-zu-Ende-Szenarien, mit Headless-Chrome und axe für die Barrierefreiheit: `sideline.feature`, `course_union.feature` (Kursverbund, Rechteentzug, Cache), `transfer.feature` (Downloads im HTTP-Pfad, Import, Backup und Restore), `languages.feature` (Deutsch, Französisch mit Übersetzung, Polnisch als Fallback).
- `js/esm/tests/` (React-Panel, ab Moodle 5.3) und `tests/js/` (AMD-Panel): Jest.
- `tests/load/`: k6-Plan, Seed- und Prüfskript für den HTTP-Lasttest.
- `tests/playwright/`: Browser-Journeys mit Playwright und axe, eigenes Seed-Skript.
- `tests/coverage.php` legt den Umfang der Abdeckungsmessung fest, `tools/coverage_gate.php` erzwingt die Untergrenze.

## Was Pipelines schon rot gemacht hat
- **Debug-Meldungen bei der Installation.** moodle-plugin-ci bricht bei jeder `debugging()`-Ausgabe ab, etwa wenn XMLDB ein `CHAR NOT NULL` mit `DEFAULT=""` meldet. Die Entwicklungs-Site zeigt die Meldung nur an. Darum wird vor jeder Auslieferung mit moodle-plugin-ci selbst geprüft (`tools/ci-local/ci.sh`).
- **moodle-cs prüft je nach Moodle-Version anders.** Auf 4.5 zählt für die Coverage nur `@covers` im Docblock direkt vor der Klasse. Darum stehen die Attribute vor dem Docblock. Testklassen liegen im Verzeichnis ihres Namensraums.
- **`tools/` wird mitgeprüft.** moodle-plugin-ci beachtet weder `phpcs.xml`-Ausnahmen noch `.phpcsignore`.
- **Im Moodle-Baum prüfen.** Einige Regeln von moodle-cs greifen nur, wenn das Plugin in einem Moodle-Baum liegt.
- **Die Werkzeugversion der CI verwenden.** Ein älteres lokales `moodle-cs` akzeptiert, was die CI ablehnt.
- **Kein `DEFAULT=""` bei `CHAR NOT NULL`.** XMLDB gibt dann eine Debug-Meldung aus, und moodle-plugin-ci wertet jede Debug-Meldung bei der PHPUnit-Initialisierung als Fehler.
- **Testserver an `127.0.0.1` binden.** `localhost` kann zu `::1` auflösen, wo der eingebaute PHP-Server nicht lauscht.
- **Server im Schritt starten, der ihn braucht.** Ein Hintergrundserver überlebt den startenden Schritt nicht zuverlässig.
- **MariaDB-Gesundheitsprüfung mit `healthcheck.sh`.** Das Image `mariadb:11.4` enthält `mysqladmin` nicht mehr.
- **k6 und Moodle-Sitzungen:** k6 leert Cookies je Iteration. Ohne `noCookiesReset` ist jede zweite Anfrage abgemeldet.
- **Das Plugin nicht per `add-plugin` hinzufügen.** `moodle-plugin-ci install` übernimmt es schon; ein zweiter Klon bricht die Installation ab.
- **Playwright und Moodle-Login:** Erst nach `networkidle` ausfüllen, sonst ersetzt Moodles JavaScript das Feld und der Login scheitert mit „invalid login token“. Benutzertouren abschalten, ihr Dialog setzt `aria-hidden` auf die Seite.
- **`db/removed_files.txt` pflegen.** Ein Plugin-Paket löscht nie etwas. Eine entfernte Datei überlebt sonst in einem in-place aktualisierten Repository.

## Voraussetzungen
Moodle 4.5 bis 5.3, PHP 8.1 oder neuer, Node.js für Build, Jest und Playwright, k6 für den Lasttest.
