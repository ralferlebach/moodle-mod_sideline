#!/bin/bash
# Run the moodle-plugin-ci pipeline of .github/workflows/moodle-plugin-ci-main.yml locally, step by step,
# with the same commands and options (docs/environment-setup.md, section 4). One-time setup: setup.sh.
#
# Usage: tools/ci-local/ci.sh <MOODLE_BRANCH> <pgsql|mariadb> <php-version> [steps]
#   steps: "all" (default) or a list such as "install codechecker phpunit".
#   SRC=<dir>  plugin to test (default: this working copy; for the release check the unpacked ZIP).
# Results: $CI_WORK/<tag>/ci-logs/rc.txt (one "step rc=N" line per step) and one log per step.
#
# Differences to GitHub, none of which changes what is checked:
#   - Moodle is cloned from the local tree of the branch when there is one (file://), else from GitHub;
#   - the database servers are the local ones (PostgreSQL 17 on 5432, MariaDB 11.4 on 3307);
#   - Behat runs against chromedriver under Xvfb instead of the Selenium container (no Docker here).
set -u
BRANCH=$1; DB=$2; PHPV=$3; STEPS=${4:-all}
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC=${SRC:-$(cd "$HERE/../.." && pwd)}
MPCI=${MPCI:-/opt/mpci}
CI_WORK=${CI_WORK:-/home/claude/ci}
CHROMEDRIVER=${CHROMEDRIVER:-$(ls /tmp/claude-0/cd/chromedriver/linux-*/chromedriver-linux64/chromedriver 2>/dev/null | tail -1)}
TAG=$(echo "$BRANCH" | sed 's/MOODLE_\([0-9]*\)_STABLE/\1/')-$DB-$PHPV
W=$CI_WORK/$TAG
declare -A TREE=([MOODLE_405_STABLE]=/home/claude/moodle405 [MOODLE_500_STABLE]=/home/claude/moodle500
    [MOODLE_501_STABLE]=/home/claude/moodle501 [MOODLE_502_STABLE]=/home/claude/moodle502 [MOODLE_503_STABLE]=/home/claude/moodle)
REPO=https://github.com/moodle/moodle.git
[[ -d ${TREE[$BRANCH]:-}/.git ]] && REPO=file://${TREE[$BRANCH]}

export PATH=$MPCI/wrap:$MPCI/ci/bin:$MPCI/ci/vendor/bin:$PATH
# moodle-plugin-ci creates the MariaDB database with the "mysql" client of the local MariaDB.
[[ -x /opt/mariadb/bin/mysql ]] && export PATH=$PATH:/opt/mariadb/bin
PB=/tmp/claude-0/phpbin-$PHPV; mkdir -p "$PB" && ln -sfn "/usr/bin/php$PHPV" "$PB/php" && export PATH=$PB:$PATH
export DB MOODLE_BRANCH=$BRANCH MPCI
DBNAME=ci_${TAG//[-.]/_}

want() { [[ $STEPS == all || " $STEPS " == *" $1 "* ]]; }
run() {
    local name=$1; shift
    "$@" > "ci-logs/$name.log" 2>&1
    local rc=$?
    echo "$name rc=$rc" | tee -a ci-logs/rc.txt
    [[ $rc -ne 0 ]] && tail -40 "ci-logs/$name.log"
    return 0
}

if want install; then
    rm -rf "$W"; mkdir -p "$W/ci-logs" "$W/plugin"
    # A clean checkout of the plugin, as actions/checkout gives it: no node_modules, no run artefacts.
    (cd "$SRC" && tar --exclude=.git --exclude=node_modules --exclude=test-results --exclude=playwright-report \
        --exclude=.pw-env -cf - .) | tar -xf - -C "$W/plugin"
    cd "$W"
    if [[ $DB == pgsql ]]; then
        PGPASSWORD=moodle psql -h 127.0.0.1 -U moodle -d postgres -qc "DROP DATABASE IF EXISTS $DBNAME" 2>/dev/null
        dbargs="--db-host=127.0.0.1 --db-user=moodle --db-pass=moodle --db-name=$DBNAME"
    else
        /opt/mariadb/bin/mariadb -uroot --socket=/tmp/mariadb.sock -e "DROP DATABASE IF EXISTS $DBNAME"
        dbargs="--db-host=127.0.0.1 --db-port=3307 --db-user=ci --db-pass=ci --db-name=$DBNAME"
    fi
    run install moodle-plugin-ci install --plugin ./plugin --repo "$REPO" $dbargs
fi
cd "$W" || exit 1
# moodle-plugin-ci keeps the paths of the last install in ONE .env file next to itself. Parallel runs would
# overwrite each other's; variables already set take precedence over that file, so every run sets its own.
web=moodle; [[ -f moodle/public/version.php ]] && web=moodle/public
export MOODLE_DIR=$W/moodle PLUGIN_DIR=$W/$web/mod/sideline
want phplint && run phplint moodle-plugin-ci phplint
want phpcpd && run phpcpd moodle-plugin-ci phpcpd
want phpmd && run phpmd moodle-plugin-ci phpmd
want codechecker && run codechecker moodle-plugin-ci codechecker --max-warnings 0 \
    --exclude=PSR1.Classes.ClassDeclaration,moodle.Commenting.TodoComment
want phpdoc && run phpdoc moodle-plugin-ci phpdoc --max-warnings 0
want validate && run validate moodle-plugin-ci validate
want savepoints && run savepoints moodle-plugin-ci savepoints
want mustache && run mustache moodle-plugin-ci mustache
want grunt && run grunt moodle-plugin-ci grunt --max-lint-warnings 0
want phpunit && run phpunit moodle-plugin-ci phpunit --fail-on-warning
if want behat; then
    # One Behat run at a time: all runs share port 8000 (behat_wwwroot) and 4444 (WebDriver).
    exec 9>/tmp/claude-0/behat.lock; flock 9
    (php -S localhost:8000 -t "$web" > ci-logs/behat-server.log 2>&1 &)
    (xvfb-run -a "$CHROMEDRIVER" --port=4444 --url-base=/wd/hub > ci-logs/chromedriver.log 2>&1 &)
    sleep 4
    MOODLE_START_BEHAT_SERVERS=NO run behat moodle-plugin-ci behat --profile chrome --tags "@mod_sideline&&~@broken" --dump
    pkill -f "php -S localhost:8000"; pkill -f chromedriver; pkill -f Xvfb
    flock -u 9
fi
