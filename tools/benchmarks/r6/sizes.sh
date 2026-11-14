#!/bin/sh
# Dev only: Usage: sizes.sh DB. Row counts and sizes of the tables of an ospos_test_r6 database, and what a thousand sales cost.
set -eu
DB="${1:?database}"
case "$DB" in ospos_test_r6*) ;; *) echo "refusing" >&2; exit 2;; esac
ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
PW=$(grep '^database.tests.password' "$ROOT/.env" | cut -d= -f2- | tr -d "'\"")
export MYSQL_PWD="$PW"
mysql -h127.0.0.1 -uadmin -e "
SELECT table_name, table_rows, ROUND(data_length/1048576,1) AS data_mb, ROUND(index_length/1048576,1) AS index_mb FROM information_schema.tables WHERE table_schema = '$DB' ORDER BY data_length + index_length DESC LIMIT 14;
SELECT COUNT(*) AS sales, ROUND(SUM(data_length+index_length)/1048576,1) AS total_logical_mb FROM information_schema.tables t, (SELECT COUNT(*) FROM $DB.ospos_sales) s WHERE t.table_schema = '$DB';"
du -sm "/var/lib/mysql/$DB" | sed 's/^/on disk MB: /'
