#!/usr/bin/env bash
# Write config.php for a Moodle test tree. Usage: mkconfig.sh <branch> (405, 500, 501, 502)
set -e
b=$1; root=/home/claude/moodle$b; port=$((8100 + ${b: -1} + (${b:0:1} - 4) * 10))
cat > $root/config.php <<CFG
<?php
unset(\$CFG);
global \$CFG;
\$CFG = new stdClass();
\$CFG->dbtype    = 'pgsql';
\$CFG->dblibrary = 'native';
\$CFG->dbhost    = 'localhost';
\$CFG->dbname    = 'moodle$b';
\$CFG->dbuser    = 'moodle';
\$CFG->dbpass    = 'moodle';
\$CFG->prefix    = 'mdl_';
\$CFG->dboptions = ['dbpersist' => 0, 'dbport' => 5432, 'dbsocket' => ''];
if (getenv('MOODLE_DB') === 'mariadb') {
    \$CFG->dbtype = 'mariadb';
    \$CFG->dbhost = '127.0.0.1';
    \$CFG->dboptions = ['dbpersist' => 0, 'dbport' => 3307, 'dbsocket' => '', 'dbcollation' => 'utf8mb4_unicode_ci'];
}
\$suffix = getenv('MOODLE_DB') === 'mariadb' ? '_maria' : '';
\$CFG->wwwroot   = 'http://localhost:$port';
\$CFG->dataroot  = '/home/claude/data$b/moodledata' . \$suffix;
\$CFG->admin     = 'admin';
\$CFG->directorypermissions = 0777;
\$CFG->phpunit_prefix   = 'phpu_';
\$CFG->phpunit_dataroot = '/home/claude/data$b/phpu' . \$suffix;
\$CFG->behat_wwwroot  = 'http://127.0.0.1:$((port + 100))';
\$CFG->behat_dataroot = '/home/claude/data$b/bht';
\$CFG->behat_prefix   = 'bht_';
\$CFG->behat_profiles = ['default' => ['browser' => 'chrome', 'wd_host' => 'http://127.0.0.1:9515',
    'capabilities' => ['extra_capabilities' => ['goog:chromeOptions' => [
        'binary' => '/opt/pw-browsers/chromium-1243/chrome-linux64/chrome',
        'args' => ['--headless=new', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage', '--window-size=1600,1200'],
    ]]]]];
require_once(__DIR__ . '/lib/setup.php');
CFG
mkdir -p /home/claude/data$b/moodledata /home/claude/data$b/phpu /home/claude/data$b/bht /home/claude/data$b/moodledata_maria /home/claude/data$b/phpu_maria
su postgres -c "psql -tAc \"SELECT 1 FROM pg_database WHERE datname='moodle$b'\"" | grep -q 1 || su postgres -c "createdb -O moodle -E UTF8 moodle$b"
echo "config for $b: wwwroot port $port"
