# environment-setup.md

## Setup der Test- & Laufzeitumgebung für mod_sideline

Anleitung, um die Verifikationsumgebung für `mod_sideline` im Container von Grund auf aufzusetzen:
Moodle 5.3, PHP 8.3, PostgreSQL 17, PHPUnit, Code-Style (phpcs/moodle-cs).

> **Konvention:** QUELLE = `/home/claude/work/moodle-mod_sideline` (Arbeitskopie, wird ausgeliefert).
> SPIEGEL = `/home/claude/moodle/public/mod/sideline` (Wegwerf-Kopie im Moodle-Baum, gegen die
> getestet wird). Nie im Spiegel entwickeln – `tools/mirror.sh` überschreibt ihn bei jedem Lauf.

> **Verifikationsstand (25.10.2026, Version 0.2.1):** Alle Abschnitte wurden im Container ausgeführt, die Versionsmatrix 4.5–5.3 in Abschnitt 8d.
> PHPUnit 172/172 auf PostgreSQL 17.11 und MariaDB 11.4.13, Behat 11/11 (davon 4 in Headless-Chrome 153),
> Jest 20/20 aus dem Moodle-Root, `grunt esm`-äquivalenter Build mit Moodles eigenem esbuild-Skript,
> phpcs 0 Fehler/0 Warnungen, Upgrade 0.1.0 → 0.2.0 gegen eine echte 0.1.0-Datenbank.
> Nicht ausgeführt: GitHub-CI, `local_moodlecheck` (PHPDoc-Prüfer; Installation aus GitHub im Container nicht erlaubt).

---

## 0. Zielzustand

| Komponente | Wert |
|---|---|
| Moodle | 5.3, Branch `MOODLE_503_STABLE` (Version 2026100500.06), Webroot `public/` |
| PHP | ≥ 8.3.0 (verifiziert mit 8.3.x) |
| DB | PostgreSQL **17** (Moodle 5.3 verlangt PG 17 / MariaDB 11.4), `dbname=moodle`, `prefix=mdl_` |
| PHPUnit | 11.5 (aus dem Core-Composer; Attribute `#[CoversClass]`, `#[DataProvider]`) |
| Moodle-Pfad | `/home/claude/moodle` |
| dataroot | `/home/claude/moodledata`, `phpunit_dataroot=/home/claude/moodledata_phpu`, `phpunit_prefix=phpu_` |
| phpcs | `/tmp/moodlecs`, `moodlehq/moodle-cs ^3.7` |

Plugin-Pfad in 5.3: **`public/mod/sideline`** (nicht mehr `mod/sideline`).

## 1. Systempakete

```bash
apt-get update -qq
apt-get install -y -qq php8.3-cli php8.3-xml php8.3-mbstring php8.3-curl php8.3-zip \
    php8.3-gd php8.3-intl php8.3-pgsql php8.3-soap php8.3-xmlrpc composer locales
```

**Stolperstein Locale:** Der PHPUnit-Init braucht `en_AU.UTF-8`:

```bash
locale-gen en_AU.UTF-8 && update-locale
```

**Stolperstein max_input_vars:** Moodle verlangt ≥ 5000. Das `-d`-Flag erreicht den Init-Subprozess
nicht, daher dauerhaft setzen:

```bash
echo "max_input_vars=6000" > /etc/php/8.3/cli/conf.d/99-moodle.ini
```

## 2. PostgreSQL 17

Die Distributionsversion (16) reicht nicht („version 17 is required“). PGDG-Repository einbinden:

```bash
apt-get install -y -qq postgresql-common
/usr/share/postgresql-common/pgdg/apt.postgresql.org.sh -y
apt-get install -y -qq postgresql-17
```

Der Cluster muss auf Port 5432 laufen (ggf. `port` in `postgresql.conf` anpassen, falls ein 16er-Cluster
den Port belegt). Rolle und Datenbank:

```bash
su postgres -c "psql -c \"CREATE USER moodle WITH PASSWORD 'moodle';\""
su postgres -c "psql -c \"CREATE DATABASE moodle OWNER moodle ENCODING 'UTF8';\""
```

`tools/mirror.sh` startet den Cluster, falls er nicht online ist.

## 3. Moodle 5.3

```bash
git clone --depth 1 -b MOODLE_503_STABLE https://github.com/moodle/moodle.git /home/claude/moodle
cd /home/claude/moodle && composer install --no-interaction
mkdir -p /home/claude/moodledata /home/claude/moodledata_phpu
php admin/cli/install.php --lang=en --wwwroot=http://localhost:8080 \
  --dataroot=/home/claude/moodledata --dbtype=pgsql --dbhost=localhost --dbname=moodle \
  --dbuser=moodle --dbpass=moodle --fullname=Dev --shortname=dev \
  --adminuser=admin --adminpass='Admin-123!' --agree-license --non-interactive
```

In `config.php` ergänzen (vor `require_once(__DIR__ . '/lib/setup.php')`):

```php
$CFG->phpunit_prefix = 'phpu_';
$CFG->phpunit_dataroot = '/home/claude/moodledata_phpu';
```

## 4. Plugin einspielen (Spiegel)

```bash
/home/claude/work/moodle-mod_sideline/tools/mirror.sh
cd /home/claude/moodle && php admin/cli/upgrade.php --non-interactive
```

## 5. PHPUnit

```bash
cd /home/claude/moodle
php public/admin/tool/phpunit/cli/init.php
vendor/bin/phpunit --testsuite mod_sideline_testsuite
```

Erwartet: `OK (169 tests, …)` für mod_sideline, dazu `slcmod_assign_testsuite` (2) und `slcmod_quiz_testsuite` (1).

Nach jeder Codeänderung: `tools/mirror.sh`, danach `init.php` erneut (baut die Testsuite-Konfiguration
neu), dann phpunit.

**Stolpersteine beim Init:**

- *„Can not use database for testing“*: Ein abgebrochener Init hinterlässt `phpu_`-Tabellen. Alle Tabellen
  mit Präfix `phpu_` per SQL droppen und neu initialisieren.
- *„dataroot not empty“*: Im phpunit-dataroot liegt eine versteckte `.htaccess`. Leeren mit
  `find /home/claude/moodledata_phpu -mindepth 1 -delete`.

## 6. Code-Style (phpcs)

```bash
mkdir -p /tmp/moodlecs && cd /tmp/moodlecs
composer require --dev --no-interaction "moodlehq/moodle-cs:^3.7"
P="$(pwd)/vendor/moodlehq/moodle-cs/moodle"
for d in Universal Modernize NormalizedArrays; do P="$P,$(pwd)/vendor/phpcsstandards/phpcsextra/$d"; done
./vendor/bin/phpcs --config-set installed_paths "$P"

cd /home/claude/work/moodle-mod_sideline
/tmp/moodlecs/vendor/bin/phpcs --standard=moodle --extensions=php .
/tmp/moodlecs/vendor/bin/phpcbf --standard=moodle --extensions=php .   # Auto-Fix
```

Stand: 0 Fehler, 0 Warnungen (die CI läuft mit `--max-warnings 0`).

## 7. Frontend (React/TypeScript/ESM)

Moodle 5.3 baut ESM-Quellen unter `js/esm/src` mit `grunt esm` (esbuild). Im Container scheitert
`npm install` im Moodle-Root an einer GitHub-Tarball-Abhängigkeit (shifter, 403). Umgehung, verifiziert:

```bash
# Werkzeugkette separat installieren und als node_modules des Moodle-Roots verlinken.
mkdir -p /tmp/fe && cd /tmp/fe && npm init -y
npm install react@19 react-dom@19 typescript@5 @types/react@19 @types/react-dom@19 jest@30 \
    jest-environment-jsdom@30 ts-jest@29 @testing-library/react@16 @testing-library/dom \
    @testing-library/jest-dom@6 jest-axe@10 esbuild@0.25 glob@10 chalk@4 @xmldom/xmldom xpath
ln -sfn /tmp/fe/node_modules /home/claude/moodle/node_modules

# Moodles eigener ESM-Build (das, was grunt esm aufruft).
cat > /tmp/buildesm.mjs <<'X'
process.chdir('/home/claude/moodle');
const {generateAliases} = await import('/home/claude/moodle/.esbuild/generate-aliases.mjs');
const {buildPluginComponents} = await import('/home/claude/moodle/.esbuild/plugin/plugincomponents.mjs');
generateAliases();
await buildPluginComponents();
X
node /tmp/buildesm.mjs
# Das Bundle gehört zum Plugin: zurück in die Arbeitskopie kopieren.
rm -rf /home/claude/work/moodle-mod_sideline/js/esm/build
cp -r /home/claude/moodle/public/mod/sideline/js/esm/build /home/claude/work/moodle-mod_sideline/js/esm/

# Jest aus dem Moodle-Root (nutzt jest.config.js des Cores).
cd /home/claude/moodle && npx jest public/mod/sideline/js/esm/tests
```

Erwartet: `114 components built`, `Tests: 20 passed`. Mit vollem Netzzugang reicht `npm install && npx grunt esm`.
Wichtig: erst `tools/mirror.sh`, dann bauen, sonst entsteht das Bundle aus alten Quellen.

## 8. Behat (mit Headless-Chrome)

Chromium liegt unter `/opt/pw-browsers/chromium-1243/chrome-linux64/chrome` (Version 153). Der vorinstallierte
ChromeDriver ist 147 und passt nicht; passend herunterladen:

```bash
mkdir -p /tmp/cd && cd /tmp/cd && npx -y @puppeteer/browsers install chromedriver@153.0.8010.12
```

`config.php` ergänzen:

```php
$CFG->behat_wwwroot  = 'http://127.0.0.1:8090';
$CFG->behat_dataroot = '/home/claude/moodledata_bht';
$CFG->behat_prefix   = 'bht_';
$CFG->behat_profiles = ['default' => [
    'browser' => 'chrome',
    'wd_host' => 'http://127.0.0.1:9515',
    'capabilities' => ['extra_capabilities' => ['goog:chromeOptions' => [
        'binary' => '/opt/pw-browsers/chromium-1243/chrome-linux64/chrome',
        'args' => ['--headless=new', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage', '--window-size=1600,1200'],
    ]]],
]];
```

```bash
mkdir -p /home/claude/moodledata_bht
php public/admin/tool/behat/cli/init.php
php -S 127.0.0.1:8090 -t /home/claude/moodle/public &          # Webserver
/tmp/cd/chromedriver/linux-153.0.8010.12/chromedriver-linux64/chromedriver --port=9515 &
vendor/bin/behat --config /home/claude/moodledata_bht/behatrun/behat/behat.yml --tags @mod_sideline
```

Erwartet: `11 scenarios (11 passed)`. Nach Codeänderungen: `tools/mirror.sh`, ggf. ESM neu bauen, dann
`php public/admin/tool/behat/cli/util.php --enable`. Szenarien mit axe-Prüfung brauchen das Tag `@accessibility`.

## 8a. MariaDB 11.4 (zweite Datenbank)

Die Distribution hat nur MariaDB 10.11; Moodle 5.3 verlangt 11.4. Das Binärarchiv von archive.mariadb.org ist erreichbar:

```bash
curl -O https://archive.mariadb.org/mariadb-11.4.13/bintar-linux-systemd-x86_64/mariadb-11.4.13-linux-systemd-x86_64.tar.gz
tar xzf mariadb-11.4.13-linux-systemd-x86_64.tar.gz -C /opt && ln -sfn /opt/mariadb-11.4.13-linux-systemd-x86_64 /opt/mariadb
apt-get install -y php8.3-mysql
/opt/mariadb/scripts/mariadb-install-db --basedir=/opt/mariadb --datadir=/home/claude/mariadb-data --user=root
/opt/mariadb/bin/mariadbd --basedir=/opt/mariadb --datadir=/home/claude/mariadb-data --user=root --port=3307 \
    --socket=/tmp/mariadb.sock --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci &
/opt/mariadb/bin/mariadb -uroot --socket=/tmp/mariadb.sock -e "CREATE DATABASE moodle DEFAULT CHARACTER SET utf8mb4 \
    COLLATE utf8mb4_unicode_ci; CREATE USER 'moodle'@'127.0.0.1' IDENTIFIED BY 'moodle'; GRANT ALL ON moodle.* TO 'moodle'@'127.0.0.1';"
```

`config.php` schaltet per Umgebungsvariable um (eigener dataroot und phpunit_dataroot):

```php
if (getenv('MOODLE_DB') === 'mariadb') {
    $CFG->dbtype = 'mariadb';
    $CFG->dbhost = '127.0.0.1';
    $CFG->dboptions = ['dbpersist' => 0, 'dbport' => 3307, 'dbsocket' => '', 'dbcollation' => 'utf8mb4_unicode_ci'];
}
```

```bash
MOODLE_DB=mariadb php public/admin/tool/phpunit/cli/init.php
MOODLE_DB=mariadb vendor/bin/phpunit --testsuite mod_sideline_testsuite
```

## 8b. Upgrade-Test 0.1.0 → 0.2.0

Vor dem Anheben der Version wurde eine Dev-Site mit 0.1.0-Daten (Instanz, Einwilligung, Protokoll, Cache) als
`pg_dump -Fc` gesichert (`/home/claude/moodle_010_snapshot.dump`). Wiederholbar:

```bash
su postgres -c "dropdb moodle && createdb -O moodle -E UTF8 moodle"
su postgres -c "pg_restore -d moodle --no-owner --role=moodle /home/claude/moodle_010_snapshot.dump"
php admin/cli/upgrade.php --non-interactive
php admin/cli/check_database_schema.php     # keine Abweichungen für sideline
```

## 8c. Messungen

```bash
php public/mod/sideline/tools/bench.php --users=200 --activities=30 --requests=1000 --workers=8 --cleanup
```

Ergebnisse und Budgets: `docs/TRACEABILITY.md`, Abschnitt Performance.

## 8d. Versionsmatrix Moodle 4.5 bis 5.3

Weitere Moodle-Bäume neben `/home/claude/moodle` (5.3):

| Baum | Branch | Layout | PHPUnit | DB (PG) | wwwroot / Behat |
|---|---|---|---|---|---|
| `/home/claude/moodle405` | MOODLE_405_STABLE | klassisch (`mod/sideline`) | 9.6 | `moodle405` | 8105 / 8205 |
| `/home/claude/moodle500` | MOODLE_500_STABLE | klassisch | 11 | `moodle500` | 8110 / 8210 |
| `/home/claude/moodle501` | MOODLE_501_STABLE | `public/` | 11 | `moodle501` | 8111 / 8211 |
| `/home/claude/moodle502` | MOODLE_502_STABLE | `public/` | 11 | `moodle502` | 8112 / 8212 |

```bash
git clone --depth 1 -b MOODLE_405_STABLE https://github.com/moodle/moodle.git /home/claude/moodle405
cd /home/claude/moodle405 && composer install --no-interaction --prefer-dist
# Composer holt die Pakete hier als Git-Klone: .git-Ordner in vendor löschen spart mehrere GB.
find vendor -mindepth 2 -maxdepth 3 -type d -name .git -prune -exec rm -rf {} +
tools/mkconfig.sh 405          # config.php, Datenroots, Datenbank
/home/claude/work/moodle-mod_sideline/tools/mirror.sh /home/claude/moodle405   # erkennt das Layout selbst
php admin/cli/install_database.php --agree-license --adminpass='Admin-123!' --fullname=M405 --shortname=m405
php admin/tool/phpunit/cli/init.php            # 5.1+: public/admin/tool/phpunit/cli/init.php
vendor/bin/phpunit --testsuite mod_sideline_testsuite
```

Behat je Version: eigener PHP-Webserver auf dem Behat-Port (`php -S 127.0.0.1:8205 -t /home/claude/moodle405`,
ab 5.1 `-t …/public`), ChromeDriver wie in Abschnitt 8.

**Frontend je Version:** Moodle 5.3 rendert das React-Panel (`js/esm`). Moodle 4.5 bis 5.2 bekommen das AMD-Panel
(`amd/src/panel.js`), weil React erst in 5.3 vollständig ist. Build wie `grunt amd` (Rollup, Babel, Terser mit Moodles Optionen):

```bash
mkdir -p /tmp/amdbuild && cd /tmp/amdbuild && npm init -y
npm install @babel/core@7.17.5 @babel/preset-env@7.16.11 babel-plugin-system-import-transformer@4 \
    babel-plugin-transform-es2015-modules-amd-lazy@2.0.1 rollup@2 rollup-plugin-terser@7 glob@7 @xmldom/xmldom xpath@0.0.32 \
    eslint@8 eslint-plugin-jsdoc@48 eslint-plugin-promise@6.0.0 @babel/eslint-parser@7.17.0 @babel/eslint-plugin@7.17
# build.js: siehe tools/amd-build.js (nutzt .grunt/babel-plugin-add-module-to-define.js des Moodle-Baums)
cd /home/claude/moodle405 && NODE_PATH=/tmp/amdbuild/node_modules node /home/claude/work/moodle-mod_sideline/tools/amd-build.js /home/claude/moodle405 /home/claude/moodle405/mod/sideline
cp mod/sideline/amd/build/* /home/claude/work/moodle-mod_sideline/amd/build/
# ESLint mit Moodles Regeln
ln -sfn /tmp/amdbuild/node_modules node_modules && ./node_modules/.bin/eslint mod/sideline/amd/src/panel.js
# Jest für das AMD-Panel
cd /tmp/fe && npm install babel-jest@30 @babel/core @babel/preset-env
NODE_PATH=/tmp/fe/node_modules npx jest --config /home/claude/work/moodle-mod_sideline/tests/js/jest.config.js
```

**PHP 8.1** (Mindestversion von Moodle 4.5) steht im Container nicht als Laufzeit zur Verfügung. Statische Prüfung:

```bash
cd /tmp/moodlecs && composer require --dev "phpcompatibility/php-compatibility:^10.0@dev"
# installed_paths um vendor/phpcompatibility/php-compatibility/PHPCompatibility ergänzen
/tmp/moodlecs/vendor/bin/phpcs --standard=PHPCompatibility --runtime-set testVersion 8.1- --extensions=php .
```

## 9. Bekannte Abweichungen zum Plugin-Template

- CI-Matrix des Templates (PG16, MariaDB 10.11, PHP 8.1, Moodle 4.5/5.0) ist veraltet → Moodle 5.3,
  PG17, MariaDB 11.4, PHP 8.3.
- `make react` (esbuild nach `js/build/`) passte nicht zu Moodle 5.3 → ist auf `grunt esm` umgestellt.
- `core_ai\manager::user_policy_accepted()` ist ein **Schreib**-Aufruf; zum Lesen
  `get_user_policy_status()` verwenden.
