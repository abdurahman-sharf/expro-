#!/bin/sh
# Dev only: Usage: kill-test.sh DB ROUNDS. Starts a worker that kills itself with SIGKILL after a random number of statements (so inside a save,
# at a different point each round), ROUNDS times, then runs the integrity counts: no document without its journal, no journal without its
# document, no stock without its document.
set -eu
DB="${1:?database}"; ROUNDS="${2:-20}"
case "$DB" in ospos_test_r6*) ;; *) echo "refusing" >&2; exit 2;; esac
ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$ROOT"
PW=$(grep '^database.tests.password' .env | cut -d= -f2- | tr -d "'\"")
export MYSQL_PWD="$PW"
LAST=$(mysql -h127.0.0.1 -uadmin -N "$DB" -e "SELECT DATE(MAX(sale_time)) FROM ospos_sales")
echo "sales before: $(mysql -h127.0.0.1 -uadmin -N "$DB" -e "SELECT COUNT(*) FROM ospos_sales")"
r=1
KILLED=0
while [ "$r" -le "$ROUNDS" ]; do
  N=$(( (r * 37 + 11) % 150 + 3 ))
  set +e
  R6_KILL_AFTER_QUERIES=$N CI_ENVIRONMENT=development MYSQL_DB_NAME="$DB" R6_NOW="$LAST 22:00:00" php -d memory_limit=256M tools/benchmarks/r6/worker.php "$((100 + r))" 600 /dev/null > /dev/null 2>&1
  CODE=$?
  set -e
  [ "$CODE" -eq 137 ] && KILLED=$((KILLED + 1))
  echo "round $r: killed after $N statements, exit code $CODE"
  r=$((r + 1))
done
echo "workers killed by SIGKILL: $KILLED of $ROUNDS"
mysql -h127.0.0.1 -uadmin -N "$DB" -e "SELECT COUNT(*) FROM information_schema.processlist WHERE db = '$DB' AND command = 'Sleep'" | sed 's/^/idle connections left: /'
echo "sales after: $(mysql -h127.0.0.1 -uadmin -N "$DB" -e "SELECT COUNT(*) FROM ospos_sales")"
mysql -h127.0.0.1 -uadmin "$DB" < tools/benchmarks/r6/integrity.sql
