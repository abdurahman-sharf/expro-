#!/bin/sh
set -eu

BASE_URL="${UI_BASE_URL:-http://127.0.0.1}"
DB_NAME="${UI_DB_NAME:-ospos_ui}"
MYSQL="${UI_MYSQL:-mysql}"
HERE="$(cd "$(dirname "$0")" && pwd)"

refuse() {
    echo "Refusing to continue: $1 (set UI_SETUP_FORCE=1 to override)" >&2
    exit 1
}

case "$BASE_URL" in
    http://localhost*|http://127.0.0.1*) ;;
    *) [ "${UI_SETUP_FORCE:-}" = "1" ] || refuse "$BASE_URL is not a local URL" ;;
esac

case "$DB_NAME" in
    *_ui|*_test) ;;
    *) [ "${UI_SETUP_FORCE:-}" = "1" ] || refuse "database $DB_NAME does not end in _ui or _test" ;;
esac

echo "Resetting database $DB_NAME"
$MYSQL -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4"

echo "Running migrations through $BASE_URL"
status=$(curl -sS -o /dev/null -w '%{http_code}' -X POST "$BASE_URL/login" -d "username=${UI_USER:-admin}&password=${UI_PASS:-pointofsale}")
case "$status" in
    301|302|303) ;;
    *) echo "Login POST returned $status; is the app running against database $DB_NAME?" >&2; exit 1 ;;
esac

echo "Loading fixtures"
$MYSQL --default-character-set=utf8mb4 "$DB_NAME" < "$HERE/seed.sql"

echo "Opening the sub-accounts of the fixture customers"
(cd "$HERE/../.." && php spark accounting:fill_accounts)

echo "Recording sales through the UI"
node "$HERE/seed-sales.mjs"

echo "Recording receivings through the UI"
node "$HERE/seed-receivings.mjs"

echo "Leaving stock that the books cannot take in by themselves"
$MYSQL "$DB_NAME" < "$HERE/seed-stock-differences.sql"
(cd "$HERE/../.." && php spark accounting:fill_accounts)

echo "Posting the opening stock through the UI"
node "$HERE/seed-opening-stock.mjs"

echo "Adding treasuries and a transfer through the UI"
node "$HERE/seed-treasuries.mjs"

echo "Entering an opening balance and a payment without a purchase for a supplier through the UI"
node "$HERE/seed-supplier-advances.mjs"

echo "Recording journals"
node "$HERE/seed-journals.mjs"

echo "Done. Run: npm run smoke"
