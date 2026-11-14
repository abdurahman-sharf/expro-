#!/bin/sh
# Dev only: the read-side measurements of one tier. Usage: run-tier.sh DB TIER_LABEL [MEMORY_LIMIT] [ENVIRONMENT]
# Starts the router on port 8086 against DB with the clock at the last day of its data, times every page of pages.json over HTTP, then the CLI
# measurements one per process, and writes everything under the evidence folder.
set -eu
DB="${1:?database}"; TIER="${2:?label}"; LIMIT="${3:-256M}"; ENVNAME="${4:-production}"
case "$DB" in ospos_test_r6*) ;; *) echo "refusing" >&2; exit 2;; esac
ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$ROOT"
EV=/tmp/claude-0/-home-user-expro-/bb728d8f-d393-5401-ac14-56f0f0a97d9f/scratchpad/readiness/R6
PW=$(grep '^database.tests.password' .env | cut -d= -f2- | tr -d "'\"")
export MYSQL_PWD="$PW"
q() { mysql -h127.0.0.1 -uadmin -N "$DB" -e "$1"; }
LAST=$(q "SELECT DATE(MAX(sale_time)) FROM ospos_sales")
CUSTOMER=$(q "SELECT customer_id FROM ospos_sales WHERE customer_id IS NOT NULL GROUP BY customer_id ORDER BY COUNT(*) DESC LIMIT 1")
CUSTOMER_DOCS=$(q "SELECT COUNT(*) FROM ospos_sales WHERE customer_id = $CUSTOMER")
SUPPLIER=$(q "SELECT supplier_id FROM ospos_receivings WHERE supplier_id IS NOT NULL GROUP BY supplier_id ORDER BY COUNT(*) DESC LIMIT 1")
CASH=$(q "SELECT account_id FROM ospos_accounting_accounts WHERE system_key = 'cash'")
OUT="$EV/$TIER-$LIMIT-$ENVNAME${R6_SUFFIX:-}"
mkdir -p "$OUT"
echo "tier=$TIER db=$DB last=$LAST customer=$CUSTOMER docs=$CUSTOMER_DOCS supplier=$SUPPLIER cash=$CASH limit=$LIMIT env=$ENVNAME uptime=$(uptime)" | tee "$OUT/meta.txt"
rm -f "$OUT/http.log"
CI_ENVIRONMENT="$ENVNAME" MYSQL_DB_NAME="$DB" R6_NOW="$LAST 20:00:00" ALLOWED_HOSTNAMES=127.0.0.1:8086 R6_HTTP_LOG="$OUT/http.log" \
  php -d variables_order=EGPCS -d memory_limit="$LIMIT" -d max_execution_time=0 -S 127.0.0.1:8086 -t public tools/benchmarks/r6/router.php > "$OUT/server.log" 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null || true' EXIT
for i in 1 2 3 4 5 6 7 8 9 10; do curl -s -o /dev/null http://127.0.0.1:8086/login && break; sleep 1; done
node tools/benchmarks/r6/http-bench.mjs --last "$LAST" --customer "$CUSTOMER" --supplier "$SUPPLIER" --cash "$CASH" --log "$OUT/http.log" --out "$OUT/http.json" --runs "${R6_RUNS:-3}" ${R6_ONLY:+--only "$R6_ONLY"} | tee "$OUT/http.txt"
kill $SERVER 2>/dev/null || true
trap - EXIT
[ -n "${R6_SKIP_CLI:-}" ] && exit 0
for name in ${R6_CLI:-readiness trial_balance aged_ledger_customers aged_ledger_suppliers sales_revenue clients_sales item_sales item_profit payments net_position year_end_preview family_journal_movements family_stock_movements family_product_balance}; do
  ALLOWED_HOSTNAMES=127.0.0.1 CI_ENVIRONMENT="$ENVNAME" MYSQL_DB_NAME="$DB" R6_NOW="$LAST 20:00:00" php -d memory_limit="$LIMIT" tools/benchmarks/r6/cli-bench.php "$name" 2>&1 | tail -1 | tee -a "$OUT/cli.jsonl"
done
