#!/bin/sh
# Dev only: snapshots FROM (mysqldump, gzip), restores the snapshot into TO and continues the generator there up to TARGET documents.
set -eu
FROM="${1:?from database}"; TO="${2:?to database}"; TARGET="${3:?target documents}"
case "$FROM$TO" in *ospos_test_r6*ospos_test_r6*) ;; *) echo "refusing" >&2; exit 2;; esac
ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$ROOT"
PW=$(grep '^database.tests.password' .env | cut -d= -f2- | tr -d "'\"")
export MYSQL_PWD="$PW"
SNAP=/home/user/r6-snap/$FROM.sql.gz
mysqldump -h127.0.0.1 -uadmin --single-transaction --quick --no-tablespaces "$FROM" | gzip -1 > "$SNAP"
mysql -h127.0.0.1 -uadmin -e "DROP DATABASE IF EXISTS \`$TO\`; CREATE DATABASE \`$TO\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"
gunzip -c "$SNAP" | mysql -h127.0.0.1 -uadmin "$TO"
export CI_ENVIRONMENT=development MYSQL_DB_NAME="$TO"
EV=/tmp/claude-0/-home-user-expro-/bb728d8f-d393-5401-ac14-56f0f0a97d9f/scratchpad/readiness/R6
export R6_LOG="$EV/gen-$TO.log"
rm -f "$R6_LOG"
nice -n 5 php tools/benchmarks/r6/generate.php run "$TARGET" > "$EV/gen-$TO.summary.json" 2> "$EV/gen-$TO.err"
