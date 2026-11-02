#!/bin/bash
# One-time setup of the local CI (docs/environment-setup.md, section 4).
#
# Installs moodle-plugin-ci 4 to $MPCI (default /opt/mpci) together with the wrappers this container needs:
#   - npm:      GitHub archive tarballs (Moodle's shifter and istanbul forks) are built from the git tag,
#               because the container proxy blocks github.com/<owner>/<repo>/archive/... but not git;
#               node_modules of an identical dependency set are reused as hard links;
#   - composer: vendor/ of a Moodle tree with the identical composer.lock is reused as hard links;
#   - chrome:   Playwright's Chromium with --no-sandbox, as the root user needs it, where chromedriver
#               looks for Chrome (/opt/google/chrome/chrome).
# Usage: tools/ci-local/setup.sh
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MPCI=${MPCI:-/opt/mpci}
CHROME=${CHROME:-$(ls -d /opt/pw-browsers/chromium-*/chrome-linux64/chrome 2>/dev/null | grep -v headless | tail -1)}

mkdir -p "$MPCI/wrap"
if [[ ! -x $MPCI/ci/bin/moodle-plugin-ci ]]; then
    (cd "$MPCI" && COMPOSER_ALLOW_SUPERUSER=1 composer create-project -n --no-dev --prefer-dist moodlehq/moodle-plugin-ci ci ^4)
fi
install -m 755 "$HERE/npm" "$HERE/composer" "$MPCI/wrap/"

# Jest toolchain for the AMD panel tests (Moodle's Babel 7.17 is too old for current babel-jest).
if [[ ! -x $MPCI/jest-amd/node_modules/.bin/jest ]]; then
    mkdir -p "$MPCI/jest-amd" && (cd "$MPCI/jest-amd" && npm init -y > /dev/null &&
        npm install --no-progress --no-audit --no-fund jest@30 jest-environment-jsdom@30 babel-jest@30 @babel/core@7 @babel/preset-env@7 jest-axe@10)
fi

mkdir -p /opt/google/chrome
rm -f /opt/google/chrome/chrome
printf '#!/bin/sh\nexec %s --no-sandbox --disable-dev-shm-usage "$@"\n' "$CHROME" > /opt/google/chrome/chrome
chmod 755 /opt/google/chrome/chrome

"$MPCI/ci/bin/moodle-plugin-ci" --version
echo "Chrome: $CHROME"
