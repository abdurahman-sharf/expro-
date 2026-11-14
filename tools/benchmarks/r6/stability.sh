#!/bin/sh
# Dev only: Usage: stability.sh DB SECONDS WORKERS. Runs WORKERS workers side by side (pinned to two cores) for SECONDS seconds on DB,
# then prints the summaries, the integrity counts and the load.
set -eu
DB="${1:?database}"; SECONDS_TO_RUN="${2:-300}"; WORKERS="${3:-8}"
case "$DB" in ospos_test_r6*) ;; *) echo "refusing" >&2; exit 2;; esac
ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$ROOT"
EV=/tmp/claude-0/-home-user-expro-/bb728d8f-d393-5401-ac14-56f0f0a97d9f/scratchpad/readiness/R6
OUT="$EV/stability-$DB"
mkdir -p "$OUT"; rm -f "$OUT"/worker-*.json
PW=$(grep '^database.tests.password' .env | cut -d= -f2- | tr -d "'\"")
export MYSQL_PWD="$PW"
LAST=$(mysql -h127.0.0.1 -uadmin -N "$DB" -e "SELECT DATE(MAX(sale_time)) FROM ospos_sales")
echo "start $(date -u +%H:%M:%S) uptime: $(uptime)" | tee "$OUT/meta.txt"
mysql -h127.0.0.1 -uadmin -N "$DB" -e "SELECT COUNT(*) FROM ospos_sales" | sed 's/^/sales before: /' | tee -a "$OUT/meta.txt"
i=1
while [ "$i" -le "$WORKERS" ]; do
  CI_ENVIRONMENT=development MYSQL_DB_NAME="$DB" R6_NOW="$LAST 21:00:00" taskset -c 0,1 php -d memory_limit=256M tools/benchmarks/r6/worker.php "$i" "$SECONDS_TO_RUN" "$OUT/worker-$i.json" > "$OUT/worker-$i.err" 2>&1 &
  i=$((i + 1))
done
wait
echo "end $(date -u +%H:%M:%S) uptime: $(uptime)" | tee -a "$OUT/meta.txt"
mysql -h127.0.0.1 -uadmin -N "$DB" -e "SELECT COUNT(*) FROM ospos_sales" | sed 's/^/sales after: /' | tee -a "$OUT/meta.txt"
mysql -h127.0.0.1 -uadmin "$DB" < tools/benchmarks/r6/integrity.sql | tee "$OUT/integrity.txt"
