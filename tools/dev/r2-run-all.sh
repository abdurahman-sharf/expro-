#!/bin/sh
# Rebuilds the database and runs the scripted cycles in order. Output goes to $R2_EVIDENCE (default /tmp/r2-evidence).
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
OUT="${R2_EVIDENCE:-/tmp/r2-evidence}"
mkdir -p "$OUT"
rm -f /tmp/r2-session-*.json; "$HERE/r2-setup.sh" > "$OUT/setup.log" 2>&1 || { echo "setup failed, see $OUT/setup.log"; exit 1; }
for step in "$@"; do
    R2_OUT="$OUT/$step.json" node "$HERE/r2-$step.mjs" > "$OUT/$step.log" 2>&1 || echo "$step exited with an error"
    tail -3 "$OUT/$step.log"
done
