#!/bin/sh
# Dev only: Usage: kill-test.sh DB ROUNDS. Starts a worker, kills it with SIGKILL a random fraction of a second to a few seconds in, ROUNDS times,
# then runs the integrity counts: no document without its journal, no journal without its document, no stock without its document.
set -eu
DB="${1:?database}"; ROUNDS="${2:-20}"
case "$DB" in ospos_test_r6*) ;; *) echo "refusing" >&2; exit 2;; esac
ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$ROOT"
PW=$(grep '^database.tests.password' .env | cut -d= -f2- | tr -d "'\"")
export MYSQL_PWD="$PW"
LAST=$(mysql -h127.0.0.1 -uadmin -N "$DB" -e "SELECT DATE(MAX(sale_time)) FROM ospos_sales")
r=1
while [ "$r" -le "$ROUNDS" ]; do
  CI_ENVIRONMENT=development MYSQL_DB_NAME="$DB" R6_NOW="$LAST 22:00:00" php -d memory_limit=256M tools/benchmarks/r6/worker.php "$((100 + r))" 600 /dev/null > /dev/null 2>&1 &
  PID=$!
  MS=$(( (r * 7919) % 2500 + 1200 ))
  perl -e "select(undef,undef,undef,$MS/1000)"
  kill -9 "$PID" 2>/dev/null || true
  wait "$PID" 2>/dev/null || true
  r=$((r + 1))
done
mysql -h127.0.0.1 -uadmin -N "$DB" -e "SELECT COUNT(*) FROM information_schema.processlist WHERE db = '$DB' AND command = 'Sleep'" | sed 's/^/idle connections left: /'
mysql -h127.0.0.1 -uadmin "$DB" < tools/benchmarks/r6/integrity.sql
