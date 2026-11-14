#!/bin/sh
# Dev only: drops and rebuilds one ospos_test_r6* database up to the point the generator starts from.
set -eu
DB="${1:?database name (ospos_test_r6...)}"
case "$DB" in ospos_test_r6*) ;; *) echo "refusing $DB" >&2; exit 2;; esac
ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$ROOT"
PW=$(grep '^database.tests.password' .env | cut -d= -f2- | tr -d "'\"")
MYSQL_PWD="$PW" mysql -h127.0.0.1 -uadmin -e "DROP DATABASE IF EXISTS \`$DB\`; CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"
export MYSQL_PWD="$PW" CI_ENVIRONMENT=development MYSQL_DB_NAME="$DB"
php spark migrate | tail -1
php spark accounting:fill_accounts | tail -1
php spark accounting:enable --currency SAR --start-date 2024-11-01 --fiscal-year-end 2024-12-31 | tail -1
php tools/benchmarks/r6/generate.php setup
