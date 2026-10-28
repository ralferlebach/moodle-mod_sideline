# Entwicklerhinweise mod_sideline

Werkzeuge, Pipelines und Testinfrastruktur. Fachliche Dokumentation: `DOCUMENTATION.md`.

## Qualitätsprüfungen (`makefile`, `phpcs.xml`, `tools/`)
- `make check`: PHPCS mit dem Moodle-Standard, PHPDoc-Prüfer, Mustache, ESLint, PHPMD, PHPCPD, Build, Jest, PHPUnit.
- `make fix`: automatische Stil- und PHPDoc-Korrekturen.
- `make test-amd`: Jest-Tests des AMD-Panels (Moodle 4.5 bis 5.2).
- `tools/mirror.sh [MOODLE_ROOT]`: spiegelt die Arbeitskopie in einen Moodle-Baum. Erkennt das Layout mit und ohne `public/`.
- `tools/amd-build.js`: AMD-Build wie `grunt amd`, auch ohne vollständige Grunt-Umgebung.
- `tools/bench.php`: Messung von Cache und Erzeugung ohne HTTP.

## Pipelines (`.github/workflows/`)
- `moodle-plugin-ci-dev.yml`: schnelle Rückmeldung auf dem Entwicklungszweig (Moodle 4.5 und 5.3).
- `moodle-plugin-ci-main.yml`: volle Matrix Moodle 4.5 bis 5.3 mit PostgreSQL und MariaDB, dazu Release-Gates: Versionsgleichlauf, Release-Artefakt, Coverage-Untergrenze, veraltete Dateien und ein zusammenfassendes Gate.
- `load-k6.yml`: HTTP-Lasttest mit k6, nur manuell (siehe `tests/load/README.md`).

## Testinfrastruktur
- `tests/*.php`: PHPUnit (`mod_sideline_testsuite`). Die Adapter haben eigene Suites unter `adapter/*/tests`.
- `tests/behat/`: Ende-zu-Ende-Szenarien, mit Headless-Chrome und axe für die Barrierefreiheit.
- `js/esm/tests/` (React-Panel, ab Moodle 5.3) und `tests/js/` (AMD-Panel): Jest.
- `tests/load/`: k6-Plan, Seed- und Prüfskript für den HTTP-Lasttest.
- `tests/coverage.php` legt den Umfang der Abdeckungsmessung fest, `tools/coverage_gate.php` erzwingt die Untergrenze.

## Was Pipelines schon rot gemacht hat
- **Im Moodle-Baum prüfen.** Einige Regeln von moodle-cs greifen nur, wenn das Plugin in einem Moodle-Baum liegt.
- **Die Werkzeugversion der CI verwenden.** Ein älteres lokales `moodle-cs` akzeptiert, was die CI ablehnt.
- **Kein `DEFAULT=""` bei `CHAR NOT NULL`.** XMLDB gibt dann eine Debug-Meldung aus, und moodle-plugin-ci wertet jede Debug-Meldung bei der PHPUnit-Initialisierung als Fehler.
- **Testserver an `127.0.0.1` binden.** `localhost` kann zu `::1` auflösen, wo der eingebaute PHP-Server nicht lauscht.
- **Server im Schritt starten, der ihn braucht.** Ein Hintergrundserver überlebt den startenden Schritt nicht zuverlässig.
- **MariaDB-Gesundheitsprüfung mit `healthcheck.sh`.** Das Image `mariadb:11.4` enthält `mysqladmin` nicht mehr.
- **k6 und Moodle-Sitzungen:** k6 leert Cookies je Iteration. Ohne `noCookiesReset` ist jede zweite Anfrage abgemeldet.
- **`db/removed_files.txt` pflegen.** Ein Plugin-Paket löscht nie etwas. Eine entfernte Datei überlebt sonst in einem in-place aktualisierten Repository.

## Voraussetzungen
Moodle 4.5 bis 5.3, PHP 8.1 oder neuer, Node.js für Build und Jest, k6 für den Lasttest.
