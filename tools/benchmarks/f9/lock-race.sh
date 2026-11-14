#!/bin/sh
# Dev only: Usage: lock-race.sh DB BUILDER SAVER SAVES [ITEM EMPLOYEE PARTY SAVERS]. Runs real sessions at once on DB: SAVERS sessions
# (default 1) each save SAVES documents (SAVER: saveSale or savePurchase) while one session builds BUILDER (saleList, saleTemp,
# receivingTemp, customerStats, summarySales, summaryPayments) over and over until every saver is done. Savers after the first
# take the next customer (PARTY + 1, +2, ...) and the next employee. LOCK_RACE_FROM and LOCK_RACE_TO bound the dates the builder
# reads. Prints what each session did and how many deadlocks the server counted meanwhile.
set -eu
DB="${1:?database (ospos_test_f9...)}"; BUILDER="${2:?builder}"; SAVER="${3:?saver}"; SAVES="${4:?saves}"
ITEM="${5:-2241}"; EMPLOYEE="${6:-1}"; PARTY="${7:-204}"; SAVERS="${8:-1}"
case "$DB" in ospos_test_f9*) ;; *) echo "refusing $DB" >&2; exit 2;; esac
ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$ROOT"
PW=$(grep '^database.tests.password' .env | cut -d= -f2- | tr -d "'\"")
export MYSQL_PWD="$PW" LOCK_RACE_SAVERS="$SAVERS"
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
deadlocks() { mysql -h127.0.0.1 -uadmin -N -e "SHOW GLOBAL STATUS LIKE 'Innodb_deadlocks'" | cut -f2; }
start() {
  RACE_READY_FILE="$WORK/$1.ready" RACE_GO_FILE="$WORK/go" MYSQL_DB_NAME="$DB" taskset -c 0,1 php tests/Support/RaceWorker.php lockRace "$2" "$ITEM" "$3" "$4" "$SAVES" "$WORK/stop" "$WORK/$1.json" > "$WORK/$1.out" 2> "$WORK/$1.err" &
}
BEFORE=$(deadlocks)
i=0
while [ "$i" -lt "$SAVERS" ]; do
  if [ "$SAVER" = saveSale ]; then start "saver$i" "$SAVER" "$((EMPLOYEE + i))" "$((PARTY + i))"; else start "saver$i" "$SAVER" "$((EMPLOYEE + i))" "$PARTY"; fi
  i=$((i + 1))
done
start builder "$BUILDER" "$EMPLOYEE" "$PARTY"
until [ "$(ls "$WORK"/*.ready 2>/dev/null | wc -l)" -eq "$((SAVERS + 1))" ]; do sleep 0.1; done
touch "$WORK/go"
wait
AFTER=$(deadlocks)
echo "builder=$BUILDER saver=$SAVER savers=$SAVERS deadlocks=$((AFTER - BEFORE))"
for f in "$WORK"/saver*.json "$WORK"/builder.json; do cat "$f"; echo; done
cat "$WORK"/*.err | head -5
