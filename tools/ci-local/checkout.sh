#!/bin/bash
# What GitHub Actions checks out of a code base, built locally (docs/environment-setup.md, section 4).
#
# The code base (default: this working copy; for the release check the unpacked ZIP) is committed to a
# throw-away git repository exactly as `git add -A` takes it, so .gitignore applies, and cloned again.
# Every file then loses its executable bit, as it happens when the code base reaches the repository
# without file modes (upload in the browser, Windows). The CI scripts must work on this checkout.
#
# Usage: tools/ci-local/checkout.sh <target-dir>
#   SRC=<dir>  code base (default: this working copy).
# Prints the files of the code base that git leaves out. Exit code 1 if there are any, apart from
# local run artefacts that .gitignore lists on purpose (node_modules, test results, environment files).
set -u
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC=${SRC:-$(cd "$HERE/../.." && pwd)}
DEST=$1
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
(cd "$SRC" && tar --exclude=.git -cf - .) | tar -xf - -C "$TMP"
cd "$TMP" || exit 1
git init -q && git add -A && git -c user.name=ci -c user.email=ci@example.com commit -qm checkout
rm -rf "$DEST"
git clone -q "$TMP" "$DEST"
rm -rf "$DEST/.git"
find "$DEST" -type f -exec chmod a-x {} +
# Files that exist in the code base but are not in the checkout.
missing=$(cd "$TMP" && git ls-files --others --ignored --exclude-standard \
    | grep -vE '(^|/)node_modules/|^tests/playwright/(test-results|playwright-report)/|\.pw-env$|\.load-env$' || true)
if [[ -n $missing ]]; then
    echo "Ignored by .gitignore, so missing on GitHub:"
    echo "$missing"
    exit 1
fi
echo "Checkout complete: $(find "$DEST" -type f | wc -l) files, none ignored, no executable bits."
