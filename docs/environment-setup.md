# environment-setup.md – Entwicklungs- und Prüfumgebung für mod_sideline

Diese Anleitung baut die Umgebung auf, in der `mod_sideline` entwickelt und **vor jeder Auslieferung** geprüft wird.

Grundsatz: Geprüft wird mit denselben Werkzeugen und Optionen wie auf GitHub, nicht mit Nachbauten. Kern ist **moodle-plugin-ci 4**, lokal ausgeführt (Abschnitt 4). Dazu kommen die Frontend-Prüfungen, die moodle-plugin-ci nicht abdeckt (Abschnitt 5), und die Abnahme des ausgelieferten ZIP (Abschnitt 8).

> **Konvention:** QUELLE = `/home/claude/work/moodle-mod_sideline` (Arbeitskopie, wird ausgeliefert).
> Moodle-Bäume unter `/home/claude/moodle*` sind Entwicklungs-Sites. `tools/mirror.sh [ROOT]` spiegelt die Quelle hinein.
> Nie im Spiegel entwickeln: Er wird bei jedem Lauf überschrieben.
> CI-Läufe legen eigene Wegwerf-Bäume unter `/home/claude/ci/<version>-<db>-<php>/` an.

---

## 0. Zielzustand

| Komponente | Wert |
|---|---|
| Moodle | 4.5, 5.0, 5.1, 5.2, 5.3 (Branches `MOODLE_405_STABLE` … `MOODLE_503_STABLE`) |
| PHP | 8.3 (Standard), 8.1 und 8.2 parallel für die Matrix |
| Datenbanken | PostgreSQL 17 (Port 5432), MariaDB 11.4 (Port 3307) |
| moodle-plugin-ci | 4.5.x unter `/opt/mpci/ci`, mit Wrappern unter `/opt/mpci/wrap` |
| Browser | Chromium 153 aus Playwright (`/opt/pw-browsers`), ChromeDriver 153, Xvfb |
| Node.js | 22 (`/opt/node22`) |
| Speicher | Die Plattensumme ist begrenzt (etwa 40 GB). Ein CI-Lauf belegt mit den Wrappern etwa 1,5 GB. |

Layout: Ab Moodle 5.1 liegt das Plugin unter `public/mod/sideline`, in 4.5 und 5.0 unter `mod/sideline`.

## 1. Systempakete und PHP

```bash
apt-get update -qq
apt-get install -y -qq php8.3-cli php8.3-{xml,mbstring,curl,zip,gd,intl,pgsql,mysql,soap,xmlrpc,pcov} \
    composer locales xvfb openjdk-17-jre-headless
locale-gen en_AU.UTF-8 && update-locale          # PHPUnit-Init braucht en_AU.UTF-8
```

PHP 8.1 und 8.2 kommen aus dem PPA `ondrej/php`. `add-apt-repository` fehlt, deshalb wird das Repository von Hand eingetragen:

```bash
curl -fsSL "https://keyserver.ubuntu.com/pks/lookup?op=get&search=0x14AA40EC0831756756D7F66C4F4EA0AAE5267A6C" \
  | gpg --dearmor > /usr/share/keyrings/ondrej-php.gpg
echo "deb [signed-by=/usr/share/keyrings/ondrej-php.gpg] https://ppa.launchpadcontent.net/ondrej/php/ubuntu noble main" \
  > /etc/apt/sources.list.d/ondrej-php.list
apt-get update
for v in 8.1 8.2; do apt-get install -y php$v-cli php$v-{pgsql,mysql,intl,xml,mbstring,curl,zip,gd,soap,bcmath}; done
update-alternatives --set php /usr/bin/php8.3
```

`max_input_vars` muss für **jede** PHP-Version mindestens 5000 sein. Das `-d`-Flag erreicht Unterprozesse nicht:

```bash
for v in 8.1 8.2 8.3; do echo "max_input_vars=5000" > /etc/php/$v/cli/conf.d/99-moodle.ini; done
```

## 2. Datenbanken

**PostgreSQL 17** (die Distribution hat nur 16):

```bash
apt-get install -y -qq postgresql-common && /usr/share/postgresql-common/pgdg/apt.postgresql.org.sh -y
apt-get install -y -qq postgresql-17
pg_ctlcluster 17 main start
su postgres -c "psql -c \"CREATE USER moodle WITH PASSWORD 'moodle' CREATEDB SUPERUSER;\""
su postgres -c "psql -c \"CREATE DATABASE moodle OWNER moodle ENCODING 'UTF8';\""
```

`CREATEDB` braucht moodle-plugin-ci, weil es seine Datenbank selbst anlegt. Der Cluster überlebt keinen Container-Neustart: `pg_ctlcluster 17 main start` (macht auch `tools/mirror.sh`).

**MariaDB 11.4** als Binärarchiv (die Distribution hat 10.11):

```bash
curl -O https://archive.mariadb.org/mariadb-11.4.13/bintar-linux-systemd-x86_64/mariadb-11.4.13-linux-systemd-x86_64.tar.gz
tar xzf mariadb-11.4.13-linux-systemd-x86_64.tar.gz -C /opt && ln -sfn /opt/mariadb-11.4.13-linux-systemd-x86_64 /opt/mariadb
/opt/mariadb/scripts/mariadb-install-db --basedir=/opt/mariadb --datadir=/home/claude/mariadb-data --user=root
/opt/mariadb/bin/mariadbd --basedir=/opt/mariadb --datadir=/home/claude/mariadb-data --user=root --port=3307 \
    --bind-address=127.0.0.1 --socket=/tmp/mariadb.sock \
    --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci &
# Etwa zehn Sekunden warten, bis "ready for connections" im Log steht.
/opt/mariadb/bin/mariadb -uroot --socket=/tmp/mariadb.sock -e "
  CREATE USER IF NOT EXISTS 'moodle'@'127.0.0.1' IDENTIFIED BY 'moodle'; GRANT ALL ON moodle.* TO 'moodle'@'127.0.0.1';
  CREATE USER IF NOT EXISTS 'ci'@'127.0.0.1' IDENTIFIED BY 'ci'; GRANT ALL ON *.* TO 'ci'@'127.0.0.1' WITH GRANT OPTION;"
```

## 3. Moodle-Bäume 4.5 bis 5.3

Die Bäume sind Entwicklungs-Sites. Ihre Git-Klone sind außerdem die Quelle für die CI-Läufe (`file://`), damit nicht jeder Lauf Moodle neu von GitHub holt.

| Baum | Branch | Layout | PHPUnit | DB (PG) | wwwroot / Behat |
|---|---|---|---|---|---|
| `/home/claude/moodle` | MOODLE_503_STABLE | `public/` | 11 | `moodle` | 8000 / 8090 |
| `/home/claude/moodle405` | MOODLE_405_STABLE | klassisch | 9.6 | `moodle405` | 8105 / 8205 |
| `/home/claude/moodle500` | MOODLE_500_STABLE | klassisch | 11 | `moodle500` | 8110 / 8210 |
| `/home/claude/moodle501` | MOODLE_501_STABLE | `public/` | 11 | `moodle501` | 8111 / 8211 |
| `/home/claude/moodle502` | MOODLE_502_STABLE | `public/` | 11 | `moodle502` | 8112 / 8212 |

```bash
B=405   # 503: Zielordner /home/claude/moodle
git clone --depth 1 -b MOODLE_${B}_STABLE https://github.com/moodle/moodle.git /home/claude/moodle$B
cd /home/claude/moodle$B && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --prefer-dist
# Composer holt die Pakete hier als Git-Klone (kein Zugriff auf die GitHub-API): .git in vendor löschen spart GB.
find vendor -mindepth 2 -maxdepth 3 -type d -name .git -prune -exec rm -rf {} +
/home/claude/work/moodle-mod_sideline/tools/mkconfig.sh $B      # config.php, Datenroots, Datenbank
/home/claude/work/moodle-mod_sideline/tools/mirror.sh /home/claude/moodle$B
php admin/cli/install_database.php --agree-license --adminpass='Admin-123!' --fullname=M$B --shortname=m$B
```

Für die tägliche Entwicklung (schneller als ein CI-Lauf):

```bash
tools/mirror.sh /home/claude/moodle && cd /home/claude/moodle
php admin/cli/upgrade.php --non-interactive
php public/admin/tool/phpunit/cli/init.php && vendor/bin/phpunit --testsuite mod_sideline_testsuite
```

**Achtung:** `init.php` gibt Debug-Meldungen (etwa von XMLDB) nur aus und läuft weiter. moodle-plugin-ci bricht bei jeder solchen Meldung ab. Die Entwicklungs-Site ersetzt daher nie den CI-Lauf aus Abschnitt 4.

## 4. moodle-plugin-ci lokal (Pflicht vor jeder Auslieferung)

### 4.1 Einrichtung

```bash
mkdir -p /tmp/claude-0/cd && cd /tmp/claude-0/cd && npx -y @puppeteer/browsers install chromedriver@153.0.8010.12
/home/claude/work/moodle-mod_sideline/tools/ci-local/setup.sh
```

`setup.sh` installiert moodle-plugin-ci 4 nach `/opt/mpci/ci` (`composer create-project … moodlehq/moodle-plugin-ci ci ^4`, wie im Workflow). Dazu kommen drei Anpassungen an diesen Container. Keine davon ändert, was geprüft wird:

| Hindernis | Lösung (`tools/ci-local/`) |
|---|---|
| Der Proxy blockt GitHub-Archive (`github.com/<owner>/<repo>/archive/<tag>.tar.gz`), Git selbst geht. Moodles `npm-shrinkwrap.json` verweist auf zwei solche Pakete (`shifter`, `istanbul`, Forks von andrewnicols). | `npm`-Wrapper: baut das Archiv aus dem Git-Tag und trägt es als lokale Datei ein, nur in der Wegwerf-Kopie des Laufs. Identische `node_modules` werden als Hardlinks wiederverwendet. |
| Composer holt ohne GitHub-API jedes Paket als vollen Git-Klon: mehrere GB pro Lauf. Die Platte läuft voll. | `composer`-Wrapper: verlinkt `vendor/` eines Moodle-Baums mit identischer `composer.lock` als Hardlinks. Composer prüft dann nur noch. |
| Kein Docker, also kein Selenium-Container. Chrome startet als root nur mit `--no-sandbox`. | `/opt/google/chrome/chrome` ist ein Wrapper um Playwrights Chromium mit `--no-sandbox`. ChromeDriver läuft unter Xvfb auf Port 4444 (`/wd/hub`), wie Selenium. |

### 4.2 Ein Lauf

```bash
tools/ci-local/ci.sh MOODLE_503_STABLE pgsql 8.3            # alle Schritte
tools/ci-local/ci.sh MOODLE_405_STABLE pgsql 8.1 "codechecker phpdoc"   # einzelne Schritte, nach einem install
SRC=/pfad/zum/entpackten/zip/sideline tools/ci-local/ci.sh MOODLE_502_STABLE mariadb 8.3   # Abnahme des ZIP
```

Schritte und Optionen sind die aus `.github/workflows/moodle-plugin-ci-main.yml`:

- `install`;
- `phplint`;
- `phpcpd` und `phpmd` (informativ, wie in der CI);
- `codechecker --max-warnings 0 --exclude=PSR1.Classes.ClassDeclaration,moodle.Commenting.TodoComment`;
- `phpdoc --max-warnings 0`;
- `validate`, `savepoints`, `mustache`;
- `grunt --max-lint-warnings 0`;
- `phpunit --fail-on-warning`;
- `behat --profile chrome --tags "@mod_sideline&&~@broken"`.

Ergebnis je Schritt steht in `/home/claude/ci/<tag>/ci-logs/rc.txt`, das Protokoll in `<schritt>.log`. Behat-Läufe warten aufeinander (gemeinsame Ports 8000 und 4444), alles andere kann parallel laufen.

### 4.3 Matrix

Wie in der CI:

| Moodle | PHP | DB |
|---|---|---|
| 4.5 | 8.1 | pgsql |
| 4.5 | 8.3 | mariadb |
| 5.0 | 8.2 | pgsql |
| 5.0 | 8.3 | mariadb |
| 5.1 | 8.3 | pgsql |
| 5.2 | 8.3 | mariadb |
| 5.3 | 8.3 | pgsql |
| 5.3 | 8.3 | mariadb |

Vor einer Auslieferung laufen mindestens 4.5/8.1/pgsql und 5.3/8.3/pgsql vollständig grün. Vor einem Release läuft die ganze Matrix. Was moodle-plugin-ci auf welcher Version anders prüft, steht in Abschnitt 9.

## 5. Frontend-Prüfungen

moodle-plugin-ci prüft mit `grunt` nur `amd/src`, CSS und Gherkin. Die React-Quellen (TypeScript), die Tests und die Playwright-Skripte prüft:

```bash
tools/ci-local/js-checks.sh /home/claude/ci/503-pgsql-8.3/moodle    # nach einem ci.sh-Lauf auf 5.3
```

| Prüfung | Werkzeug |
|---|---|
| ESLint mit Moodles Regeln für alle JS/TS-Dateien (`amd/src`, `js/esm/src`, `js/esm/tests`, `tests/js`, `tests/playwright`) | `npx eslint --max-warnings 0` |
| Moodles React-Lint | `grunt eslint:react --max-lint-warnings=0` (vorher `grunt esm`: erzeugt `tsconfig.aliases.json` und die Jest-Aliase) |
| TypeScript strikt, nur das Plugin | `tsc --noEmit` mit eingeschränktem `include` |
| Jest React-Panel (mit axe) | `npx jest public/mod/sideline/js/esm/tests` (dazu `jest-axe@10`, `--no-save`) |
| Jest AMD-Panel (mit axe) | `tests/js/jest.config.js` mit eigener Werkzeugkette `/opt/mpci/jest-amd` (Moodles Babel 7.17 ist zu alt für babel-jest 30); läuft außerhalb des Moodle-Baums, sonst findet Node Moodles `node_modules` zuerst |
| ESM-Bundle und AMD-Bundle aktuell | `grunt esm` und `grunt amd` neu bauen, mit der Quelle vergleichen |

Nach einer Änderung an `js/esm/src` oder `amd/src` werden die Bundles im CI-Baum gebaut und zurückkopiert:

```bash
cd /home/claude/ci/503-pgsql-8.3/moodle && npx grunt esm --root=public/mod/sideline && npx grunt amd --root=public/mod/sideline
cp -r public/mod/sideline/js/esm/build /home/claude/work/moodle-mod_sideline/js/esm/
cp public/mod/sideline/amd/build/* /home/claude/work/moodle-mod_sideline/amd/build/
```

Das AMD-Bundle ist auf 4.5 und 5.3 byte-gleich, die CI kann es also auf jeder Version prüfen.

## 6. Browser-Journeys und Lasttest

- Playwright: `tests/playwright/README.md` (`make playwright` gegen eine laufende Entwicklungs-Site, 5.3 und 4.5).
- k6: `tests/load/README.md`. Der Server läuft mit `PHP_CLI_SERVER_WORKERS=8`.

Hintergrundserver überleben das Ende eines Befehls nicht zuverlässig. Server, Seed und Test laufen deshalb im selben Befehl, am Ende wird der Server mit `pkill` beendet.

## 7. Coverage

```bash
cd /home/claude/moodle
php -d pcov.enabled=1 -d pcov.directory=$PWD/public/mod/sideline vendor/bin/phpunit \
  --testsuite mod_sideline_testsuite,slcmod_assign_testsuite,slcmod_quiz_testsuite --coverage-clover /tmp/clover.xml
php public/mod/sideline/tools/coverage_gate.php /tmp/clover.xml 80
```

Ab 5.1 muss `pcov.directory` auf `public/mod/sideline` zeigen, sonst misst pcov nichts. Gezählt wird nur Code, der in `CoversClass` oder `CoversFunction` steht (PHPUnit 11).

## 8. Auslieferung

Reihenfolge, ohne Ausnahme:

1. Version in `version.php` und beiden `adapter/*/version.php` anheben, gleiche Version und gleiches Release (Gleichlauf-Job).
2. `docs/CHANGELOG.md` ergänzen. Entfernte oder verschobene Dateien in `db/removed_files.txt` eintragen.
3. ZIP bauen: volle Codebase ohne `.git`, `node_modules`, `test-results`, `playwright-report`. Name `mod_sideline-<release>.zip`, Wurzelordner `sideline/`.
4. ZIP in ein leeres Verzeichnis entpacken und die Matrix aus Abschnitt 4.3 mit `SRC=<entpackt>/sideline` laufen lassen. Die Installation in eine frische Moodle-Site ist dabei Teil von `install`.
5. `tools/ci-local/js-checks.sh` mit derselben Quelle.
6. Zusätzlich prüfen:
   - das Upgrade einer Entwicklungs-Site mit der Vorversion (`php admin/cli/upgrade.php`, danach `php admin/cli/check_database_schema.php`);
   - die Stale-Files-Liste: Kein Pfad aus `db/removed_files.txt` existiert;
   - der Versionsgleichlauf.
7. Erst wenn alles grün ist, wird ausgeliefert.

## 9. Stolpersteine

- **Debug-Meldungen brechen die CI ab.** moodle-plugin-ci wertet jede `debugging()`-Ausgabe bei Installation und PHPUnit-Init als Fehler. Typisch: `CHAR NOT NULL` mit `DEFAULT=""` in `install.xml` oder `upgrade.php`. XMLDB „repariert“ das zwar, meldet es aber.
- **moodle-cs prüft je nach Moodle-Version anders.**
  - 4.5 (PHPUnit 9) kennt keine Coverage-Attribute und sucht `@covers` im Docblock **direkt** vor der Klasse.
  - Ab 5.0 reichen die Attribute.
  - Lösung für beide: erst die Attribute, dann der Docblock mit `@covers`, dann die Klasse.
- **Namensraum = Verzeichnis** auch für Tests: `namespace mod_sideline\local\ai` gehört nach `tests/local/ai/`.
- **`tools/` wird mitgeprüft.** moodle-plugin-ci beachtet weder `phpcs.xml`-Ausnahmen noch `.phpcsignore`.
- **Test-Fixtures** per `$CFG->dirroot` laden, mit `global $CFG;` auf Dateiebene. PHPUnit lädt Testdateien aus einer Funktion heraus.
- **Das Plugin nicht per `add-plugin` hinzufügen.** `install --plugin` übernimmt es schon; ein zweiter Klon bricht ab.
- **Testserver an `127.0.0.1` binden** (`localhost` kann `::1` sein) und im selben Schritt starten, der ihn braucht.
- **MariaDB-Gesundheitsprüfung** mit `healthcheck.sh`; das Image `mariadb:11.4` hat `mysqladmin` nicht mehr.
- **Laufende Skripte nicht bearbeiten.** bash liest ein Skript stückweise; wer `ci.sh` während eines Laufs ändert, bekommt Syntaxfehler mitten im Lauf. Änderungen in eine neue Datei schreiben und mit `mv` ersetzen.
- **Parallele moodle-plugin-ci-Läufe** teilen sich dessen `.env` (Pfad des zuletzt installierten Baums). `ci.sh` setzt `MOODLE_DIR` und `PLUGIN_DIR` deshalb selbst. Gesetzte Variablen haben Vorrang vor der Datei.
- **`pkill -f <muster>`** trifft auch die eigene Shell, wenn das Muster in ihrer Befehlszeile steht. Server besser über ihre PID beenden (`… & SPID=$!; …; kill $SPID`).
- **Platte voll** zeigt sich als „Avail 0“ bei wenig „Used“ und als seltsame Composer-Fehler („sha1 file … write error“). Alte CI-Bäume und `~/.cache/composer/vcs` löschen.
- **k6 und Moodle-Sitzungen:** `noCookiesReset`, sonst ist jede zweite Anfrage abgemeldet.
- **Playwright-Login:** erst nach `networkidle` ausfüllen; Benutzertouren im Seed abschalten.
- `core_ai\manager::user_policy_accepted()` **schreibt**; zum Lesen `get_user_policy_status()`.

## 10. Verifikationsstand

Stand 02.11.2026, Version 0.3.2: Alle Abschnitte dieser Anleitung sind im Container ausgeführt. Die CI-Matrix aus Abschnitt 4.3 lief vollständig grün auf dem entpackten Auslieferungs-ZIP, ebenso `js-checks.sh` und Playwright auf 4.5 und 5.3. Einzelheiten: `docs/TRACEABILITY.md`, Abschnitt Läufe.

Nicht lokal nachgebildet: der Selenium-Container (stattdessen ChromeDriver mit demselben Chromium, Profil `chrome`) und die GitHub-Dienste für PostgreSQL und MariaDB (stattdessen lokale Server derselben Hauptversionen).
