#!/bin/bash
# The "upgrade" job of moodle-plugin-ci-main.yml locally: install Moodle with the previous release of
# the plugin, seed data with the old code, replace the plugin by the current code base, upgrade, check
# the schema and the data (docs/environment-setup.md, section 4).
#
# Usage: tools/ci-local/upgrade.sh <MOODLE_BRANCH> <pgsql|mariadb> <php-version> <previous-release-dir>
#   previous-release-dir: the unpacked previous release (for example the 0.3.2 ZIP or a git worktree).
#   SRC=<dir>  current code base (default: this working copy; for the release check the unpacked ZIP).
#   COPYOVER=1 first copy the current code over the old directory, as the cells from 0.4.1 do: the upgrade
#              must stop and name db/caches.php (#31), then the directory is replaced and the upgrade runs.
set -u
BRANCH=$1; DB=$2; PHPV=$3; PREV=$4
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC=${SRC:-$(cd "$HERE/../.." && pwd)}
TAG=upgrade-$(echo "$BRANCH" | sed 's/MOODLE_\([0-9]*\)_STABLE/\1/')-$DB-$(basename "$PREV")
CI_WORK=${CI_WORK:-/home/claude/ci}
SRC=$PREV CI_TAG=$TAG CI_WORK=$CI_WORK bash "$HERE/ci.sh" "$BRANCH" "$DB" "$PHPV" install > /dev/null
grep -q "install rc=0" "$CI_WORK/$TAG/ci-logs/rc.txt" || { echo "install of the previous release failed"; exit 1; }
cd "$CI_WORK/$TAG" || exit 1
PB=/tmp/claude-0/phpbin-$PHPV; export PATH=$PB:$PATH
root=moodle; [[ -d moodle/public ]] && root=moodle/public
php moodle/admin/cli/install_database.php --agree-license --adminpass=Admin1! --adminemail=admin@example.com \
    --fullname=CI --shortname=CI > install-site.log 2>&1 || { tail -20 install-site.log; exit 1; }
cp "$SRC/tools/ci/upgrade_seed.php" "$root/mod/sideline/upgrade_seed.php"
php "$root/mod/sideline/upgrade_seed.php" || exit 1
if [[ ${COPYOVER:-0} == 1 ]]; then
    (cd "$SRC" && tar --exclude=.git --exclude=node_modules -cf - .) | tar -xf - -C "$root/mod/sideline"
    if php moodle/admin/cli/upgrade.php --non-interactive > upgrade-copyover.log 2>&1; then
        echo "FAIL the upgrade ran although db/caches.php of the old release is still there"; exit 1
    fi
    grep -q "removed are still in its directory: db/caches.php" upgrade-copyover.log \
        || { tail -30 upgrade-copyover.log; echo "FAIL the upgrade did not name the removed file"; exit 1; }
    echo "PASS the upgrade stops and names db/caches.php while it is still in the directory"
fi
rm -rf "$root/mod/sideline" && mkdir -p "$root/mod/sideline"
(cd "$SRC" && tar --exclude=.git --exclude=node_modules -cf - .) | tar -xf - -C "$root/mod/sideline"
php moodle/admin/cli/upgrade.php --non-interactive > upgrade.log 2>&1 || { tail -30 upgrade.log; exit 1; }
php moodle/admin/cli/check_database_schema.php || exit 1
php "$root/mod/sideline/tools/ci/upgrade_verify.php"
