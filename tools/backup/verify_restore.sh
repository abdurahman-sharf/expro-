#!/usr/bin/env bash
#
# Takes a backup of a database the way the release notes ask (one consistent dump, with routines and triggers),
# restores it into another database, and checks the copy: every table holds the same rows (CHECKSUM TABLE), the chain
# of the electronic documents goes on unbroken (each counter follows the one before it and each document carries the
# hash of the one before it), and the sealed secrets of the certificates are still sealed text. The master key file of
# the vault is never in the dump: it is backed up on its own (docs/tax/phase2-release.md), and the restored secrets
# open only with it.
#
#   tools/backup/verify_restore.sh <source database> <target database>
#
# For test databases only: the target is dropped and created again. It refuses a target whose name does not contain
# "test" or "restore", so that it is never pointed at the database of a shop.

set -euo pipefail

source_db="${1:-}"
target_db="${2:-}"

if [ -z "$source_db" ] || [ -z "$target_db" ] || [ "$source_db" = "$target_db" ]; then
    echo "Give a source database and another target database." >&2
    exit 2
fi

case "$target_db" in
    *test*|*restore*) ;;
    *) echo "The target $target_db is not a test database: refused." >&2; exit 2 ;;
esac

dump="$(mktemp)"
trap 'rm -f "$dump"' EXIT

started=$(date +%s)
mysqldump --single-transaction --routines --triggers --events --hex-blob --default-character-set=utf8mb4 "$source_db" > "$dump"
dumped=$(date +%s)

mysql -e "DROP DATABASE IF EXISTS \`$target_db\`; CREATE DATABASE \`$target_db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;"
mysql "$target_db" < "$dump"
restored=$(date +%s)

tables=$(mysql -N -e "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$source_db' AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")
different=0
count=0

for table in $tables; do
    count=$((count + 1))
    left=$(mysql -N -e "CHECKSUM TABLE \`$source_db\`.\`$table\`" | awk '{print $2}')
    right=$(mysql -N -e "CHECKSUM TABLE \`$target_db\`.\`$table\`" | awk '{print $2}')

    if [ "$left" != "$right" ]; then
        echo "DIFFERENT $table: $left / $right"
        different=$((different + 1))
    fi
done

prefix="${DB_PREFIX:-ospos_}"

chain() {
    local db="$1" table="\`$1\`.\`${prefix}einvoice_documents\`"

    if ! mysql -N -e "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$db' AND TABLE_NAME = '${prefix}einvoice_documents'" | grep -q 1; then
        echo "0 0"
        return
    fi

    mysql -N -e "SELECT (SELECT COUNT(*) FROM $table), (SELECT COUNT(*) FROM $table d JOIN $table p ON p.company_id = d.company_id AND p.icv = d.icv - 1 WHERE d.previous_hash <> p.invoice_hash)"
}

read -r documents breaks <<< "$(chain "$target_db")"
read -r _ source_breaks <<< "$(chain "$source_db")"

plain=$(mysql -N -e "SELECT COUNT(*) FROM \`$target_db\`.\`${prefix}einvoice_credentials\` WHERE secret_sealed NOT LIKE 'kv1:%' OR private_key_sealed NOT LIKE 'kv1:%'" 2>/dev/null || echo 0)

echo "Tables: $count, different: $different. Documents: $documents, breaks in the chain: $breaks (in the source: $source_breaks). Secrets not sealed: $plain."
echo "Dump: $(du -h "$dump" | cut -f1) in $((dumped - started)) s; restore in $((restored - dumped)) s."

if [ "$different" -ne 0 ] || [ "$breaks" -ne "$source_breaks" ] || [ "$plain" -ne 0 ]; then
    exit 1
fi
