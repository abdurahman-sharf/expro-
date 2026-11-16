#!/bin/sh
# Rebuilds the readiness database from nothing: migrations through the login page, the UI fixture people and items,
# accounting turned on, then the fixture of r2-setup.mjs made through the real forms.
# Needs the dev server running against $R2_DB (default ospos_test_r2_ui) on $R2_BASE (default http://127.0.0.1:8082).
set -eu
HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$HERE/../.."
DB="${R2_DB:-ospos_test_r2_ui}"
BASE="${R2_BASE:-http://127.0.0.1:8082}"
MYSQL_PWD="$(grep '^database.tests.password' "$ROOT/.env" | head -1 | cut -d= -f2- | sed "s/^['\"]//;s/['\"]$//")"
export MYSQL_PWD
M="mysql -h127.0.0.1 -uadmin --default-character-set=utf8mb4"
case "$DB" in ospos_test_*) ;; *) echo "refusing database $DB" >&2; exit 1;; esac
$M -e "DROP DATABASE IF EXISTS \`$DB\`; CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4"
curl -sS -o /dev/null -X POST "$BASE/login" -d "username=admin&password=pointofsale"
(cd "$ROOT" && CI_ENVIRONMENT=development MYSQL_DB_NAME="$DB" php spark setup:dev-owner --username admin --password "${R2_ADMIN_PASSWORD:-pointofsale}")
python3 - "$ROOT/tools/ui-smoke/seed.sql" <<'PY' > /tmp/r2-seed.sql
import sys
s = open(sys.argv[1]).read()
drop = ('ospos_accounting_posting_failures', 'ospos_accounting_fiscal_years', 'REPLACE INTO ospos_app_config', 'ospos_expenses (', 'ospos_giftcards', 'INSERT INTO ospos_inventory')
print(';\n'.join(st for st in s.split(';\n') if not any(d in st for d in drop)) + ';\nUPDATE ospos_item_quantities SET quantity=0;')
PY
$M "$DB" < /tmp/r2-seed.sql
if [ -n "${R2_ADMIN_PASSWORD:-}" ]; then
    hash=$(R2_ADMIN_PASSWORD="$R2_ADMIN_PASSWORD" php -r 'echo password_hash(getenv("R2_ADMIN_PASSWORD"), PASSWORD_DEFAULT);')
    $M "$DB" -e "UPDATE ospos_employees SET password='$hash', hash_version=2 WHERE username='admin'"
fi
cd "$ROOT"
spark() { CI_ENVIRONMENT=development MYSQL_DB_NAME="$DB" php spark "$@"; }
spark accounting:enable --currency SAR --start-date 2026-01-01 || true
spark accounting:fill_accounts
spark accounting:enable --currency SAR --start-date 2026-01-01
R2_DB="$DB" R2_BASE="$BASE" node tools/dev/r2-setup.mjs
