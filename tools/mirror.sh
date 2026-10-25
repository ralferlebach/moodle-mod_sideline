#!/usr/bin/env bash
# Mirror the working copy into the Moodle tree (a throw-away copy, never edit it there).
#
# Usage: tools/mirror.sh [MOODLE_ROOT]
#   default MOODLE_ROOT is /home/claude/moodle (Moodle 5.3 layout, plugins below public/)
#
# Afterwards run the upgrade and re-initialise PHPUnit:
#   php admin/cli/upgrade.php --non-interactive
#   php public/admin/tool/phpunit/cli/init.php
set -euo pipefail

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ROOT="${1:-/home/claude/moodle}"
DEST="$ROOT/public/mod/sideline"

# Start PostgreSQL when it is not running: it does not survive a container restart.
if command -v pg_lsclusters >/dev/null && ! pg_lsclusters -h | grep -q online; then
    pg_ctlcluster 17 main start && sleep 2
fi

rm -rf "$DEST"
mkdir -p "$DEST"
# Everything except VCS data and dependencies (the ESM build output is part of the plugin).
tar -C "$SRC" --exclude=.git --exclude=node_modules -cf - . | tar -C "$DEST" -xf -
echo "Mirrored $SRC -> $DEST"
