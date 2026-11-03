#!/bin/bash
# Frontend checks beyond moodle-plugin-ci (docs/environment-setup.md, section 5), in a Moodle 5.3 tree that
# ci.sh has installed (it has Moodle's node_modules): every JavaScript and TypeScript file of the plugin
# against Moodle's ESLint rules (sources, tests, Playwright), Moodle's React lint task, a strict TypeScript
# type-check, Jest for both panels and a rebuild of the ESM bundle that must match the committed one.
#
# Usage: tools/ci-local/js-checks.sh [MOODLE_ROOT]   (default: $CI_WORK/503-pgsql-8.3/moodle)
# The GitHub frontend job runs the same script (SRC=checkout, MPCI=runner temp directory).
set -u
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC=${SRC:-$(cd "$HERE/../.." && pwd)}
ROOT=${1:-${CI_WORK:-/home/claude/ci}/503-pgsql-8.3/moodle}
P=public/mod/sideline
cd "$ROOT" || exit 1
rc=0
step() { local name=$1; shift; if "$@"; then echo "$name rc=0"; else echo "$name rc=1"; rc=1; fi; }

# The plugin as it is in SRC.
rm -rf "$P" && mkdir -p "$P" && (cd "$SRC" && tar --exclude=.git --exclude=node_modules -cf - .) | tar -xf - -C "$P"

# grunt esm also writes tsconfig.aliases.json and the Jest aliases, which tsc and Jest need.
npx grunt esm --root=$P > /dev/null 2>&1
step esm-build-current diff -r "$SRC/js" "$P/js"
step eslint npx eslint --max-warnings 0 $P/amd/src $P/js/esm/src/*.ts $P/js/esm/src/*.tsx $P/js/esm/tests/*.tsx \
    $P/tests/js $P/tests/playwright/*.js
step eslint-react npx grunt eslint:react --root=$P --max-lint-warnings=0
cat > tsconfig.sideline.json <<'EOF'
{"extends": "./tsconfig.json", "include": ["public/mod/sideline/js/esm/src/**/*", "node_modules/@types/**/*.d.ts"],
 "references": []}
EOF
step tsc npx tsc --noEmit -p tsconfig.sideline.json
rm -f tsconfig.sideline.json
# jest-axe is no Moodle dependency; without it the axe test of the React panel is skipped. Same version as in
# the pinned AMD toolchain.
[[ -d node_modules/jest-axe ]] || npm install --no-save --no-progress --no-audit --no-fund jest-axe@10.0.0 > /dev/null 2>&1
step jest-react npx jest $P/js/esm/tests
# The AMD tests need a newer Babel than Moodle's own (7.17): own toolchain, pinned by tools/ci-local/jest-amd
# (package.json and package-lock.json), installed outside the Moodle tree and run from SRC so that Node does not
# find Moodle's node_modules in a parent directory first. Reinstalled whenever the lock file changes.
J=${MPCI:-/opt/mpci}/jest-amd/node_modules
if ! cmp -s "$SRC/tools/ci-local/jest-amd/package-lock.json" "${J%/node_modules}/package-lock.json" || [[ ! -x $J/.bin/jest ]]; then
    rm -rf "${J%/node_modules}" && mkdir -p "${J%/node_modules}"
    cp "$SRC/tools/ci-local/jest-amd/package.json" "$SRC/tools/ci-local/jest-amd/package-lock.json" "${J%/node_modules}/"
    (cd "${J%/node_modules}" && npm ci --no-progress --no-audit --no-fund > /dev/null 2>&1)
fi
step jest-amd bash -c "cd '$SRC/tests/js' && NODE_PATH=$J $J/.bin/jest --config jest.config.js"
npx grunt amd --root=$P > /dev/null 2>&1
step amd-build-current diff -r "$SRC/amd" "$P/amd"
exit $rc
