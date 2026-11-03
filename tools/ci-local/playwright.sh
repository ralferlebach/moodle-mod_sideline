#!/bin/bash
# The workflow .github/workflows/playwright.yml locally (docs/environment-setup.md, section 4): a fresh
# Moodle clone with the plugin, installed with install.php on the local PostgreSQL, seeded with
# tests/playwright/seed.php, served by php -S on 127.0.0.1:8000, then the browser journeys.
#
# Usage: tools/ci-local/playwright.sh <MOODLE_BRANCH> [runs] [repeat-group]
#   runs:          full runs, each on a freshly seeded course (default 1).
#   repeat-group:  afterwards the group journey this many times in a row on one course (default 0).
#   SRC=<dir>      code base (default: this working copy; for the release check a checkout.sh tree).
#   MOODLE_REPO=github  clone the branch head from GitHub instead of the local tree.
# Results: $CI_WORK/pw-<branch>/pw-*.log; exit code 1 if any run failed.
set -u
BRANCH=$1; RUNS=${2:-1}; REPEAT=${3:-0}
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC=${SRC:-$(cd "$HERE/../.." && pwd)}
CI_WORK=${CI_WORK:-/home/claude/ci}
SHORT=$(echo "$BRANCH" | sed 's/MOODLE_\([0-9]*\)_STABLE/\1/')
W=$CI_WORK/pw-$SHORT
DBNAME=pw_$SHORT
declare -A TREE=([MOODLE_405_STABLE]=/home/claude/moodle405 [MOODLE_500_STABLE]=/home/claude/moodle500
    [MOODLE_501_STABLE]=/home/claude/moodle501 [MOODLE_502_STABLE]=/home/claude/moodle502 [MOODLE_503_STABLE]=/home/claude/moodle)
REPO=https://github.com/moodle/moodle.git
[[ ${MOODLE_REPO:-} != github && -d ${TREE[$BRANCH]:-}/.git ]] && REPO=file://${TREE[$BRANCH]}
export PATH=/tmp/claude-0/phpbin-8.3:$PATH
mkdir -p /tmp/claude-0/phpbin-8.3 && ln -sfn /usr/bin/php8.3 /tmp/claude-0/phpbin-8.3/php
export WWWROOT=http://127.0.0.1:8000

rm -rf "$W" && mkdir -p "$W" && cd "$W" || exit 1
git clone -q --depth 1 --branch "$BRANCH" "$REPO" moodle || exit 1
web=moodle; [[ -f moodle/public/version.php ]] && web=moodle/public
mkdir -p "$web/mod/sideline" && (cd "$SRC" && tar --exclude=.git --exclude=node_modules --exclude=test-results \
    --exclude=playwright-report --exclude=.pw-env -cf - .) | tar -xf - -C "$web/mod/sideline"
# Like ci.sh: with the same composer.lock as a local tree, its vendor/ is linked in instead of downloaded again.
LOCAL=${TREE[$BRANCH]:-}
if [[ -n $LOCAL && -d $LOCAL/vendor ]] && cmp -s "$LOCAL/composer.lock" moodle/composer.lock; then
    cp -al "$LOCAL/vendor" moodle/vendor
else
    (cd moodle && composer install --no-dev --no-interaction --quiet) || exit 1
fi
PGPASSWORD=moodle psql -h 127.0.0.1 -U moodle -d postgres -qc "DROP DATABASE IF EXISTS $DBNAME" 2>/dev/null
# On GitHub the PostgreSQL service creates the database; here it is created like that.
PGPASSWORD=moodle psql -h 127.0.0.1 -U moodle -d postgres -qc "CREATE DATABASE $DBNAME ENCODING 'UTF8'" || exit 1
rm -rf "$W/data" && mkdir -p "$W/data"
php moodle/admin/cli/install.php --non-interactive --agree-license \
    --dbtype=pgsql --dbhost=127.0.0.1 --dbport=5432 --dbname=$DBNAME --dbuser=moodle --dbpass=moodle \
    --wwwroot="$WWWROOT" --dataroot="$W/data" --fullname="Sideline browser" --shortname="browser" \
    --adminpass="Admin!23" --adminemail=ci@example.invalid > install.log 2>&1 || { tail -20 install.log; exit 1; }
PW=$web/mod/sideline/tests/playwright
(cd "$PW" && npm ci --silent > /dev/null 2>&1) || { echo "npm ci failed"; exit 1; }
fuser -k 8000/tcp > /dev/null 2>&1
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8000 -t "$web" > server.log 2>&1 &
server=$!
trap 'kill $server 2>/dev/null' EXIT
for i in $(seq 1 40); do
    code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$WWWROOT/login/index.php" || true)
    [[ $code == 200 ]] && break
    sleep 3
done
seed() {
    php "$web/mod/sideline/tests/playwright/seed.php" | sed -n "s/^export \(SL_[A-Z0-9_]*\)='\(.*\)'$/export \1='\2'/p" > seed.env
}
rc=0
for run in $(seq 1 "$RUNS"); do
    seed
    (set -a; . ./seed.env; set +a; cd "$PW" && CI=1 npx playwright test) > "pw-run$run.log" 2>&1
    r=$?
    echo "run $run rc=$r: $(grep -E '^\s+[0-9]+ (passed|failed|flaky)' "pw-run$run.log" | tr -s ' ' | tr '\n' ' ')"
    [[ $r -ne 0 ]] && rc=1
done
if [[ $REPEAT -gt 0 ]]; then
    seed
    (set -a; . ./seed.env; set +a; cd "$PW" && CI=1 npx playwright test -g "group member" --repeat-each="$REPEAT") \
        > pw-group-repeat.log 2>&1
    r=$?
    echo "group journey x$REPEAT on one course rc=$r: $(grep -E '^\s+[0-9]+ (passed|failed|flaky)' pw-group-repeat.log \
        | tr -s ' ' | tr '\n' ' ')"
    [[ $r -ne 0 ]] && rc=1
fi
exit $rc
