#!/usr/bin/env bash
# Tests backup.sh and restore.sh for real against a development database: backup, verify, a
# corrupted backup, retention, a refused restore, destroy, restore, compare. It builds a small
# database of its own (Arabic text, decimals, timestamps, a blob, a trigger, a routine, a view) and
# a copy of the application folder in a temporary directory, so nothing of the application is touched.
#
#   MYSQL_HOST_NAME=... MYSQL_USERNAME=... MYSQL_PASSWORD=... tools/ops/selftest.sh [DATABASE]
#
# DATABASE (default ospos_test_ops_selftest) must start with ospos_test_: it is dropped and made
# again. The database user must be allowed to create databases of that name.

set -uo pipefail

# shellcheck source=tools/ops/lib.sh
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

SELFTEST_DB=${1:-ospos_test_ops_selftest}
[[ "$SELFTEST_DB" == ospos_test_* ]] || die "$EXIT_USAGE" "the self-test only works on a database whose name starts with ospos_test_ (it drops it)"
valid_name "$SELFTEST_DB" || die "$EXIT_USAGE" "bad database name"

SAVED_PASSWORD=${MYSQL_PASSWORD:-}
SAVED_USER=${MYSQL_USERNAME:-}
SAVED_HOST=${MYSQL_HOST_NAME:-}
export MYSQL_DB_NAME=$SELFTEST_DB

load_credentials
REAL_MYSQL=$MYSQL_BIN
REAL_DUMP=$DUMP_BIN

WORK=$(mktemp -d "${TMPDIR:-/tmp}/ospos-selftest.XXXXXX")
APP="$WORK/app"
BACKUPS="$WORK/backups"
OUT="$WORK/out"
ARGV="$WORK/argv.log"
RESTORED="${SELFTEST_DB}_restored"
mkdir -p "$APP/writable/uploads" "$APP/writable/accounting_attachments" "$APP/writable/cache" "$APP/writable/logs" "$BACKUPS" "$WORK/bin"
: >"$WORK/all-output.txt"
: >"$ARGV"

for tool in mysql mysqldump; do
    real=$REAL_MYSQL
    [ "$tool" = mysqldump ] && real=$REAL_DUMP
    printf '#!/bin/bash\nprintf "%%s\\n" "$*" >> "%s"\nexec "%s" "$@"\n' "$ARGV" "$real" >"$WORK/bin/$tool"
    chmod +x "$WORK/bin/$tool"
done

trap 'sql -e "DROP DATABASE IF EXISTS \`$SELFTEST_DB\`; DROP DATABASE IF EXISTS \`$RESTORED\`" >/dev/null 2>&1; rm -rf -- "$WORK"; cleanup_tmp' EXIT

PASSED=0
FAILED=0

ok() { PASSED=$((PASSED + 1)); printf 'ok - %s\n' "$1"; }
not_ok() { FAILED=$((FAILED + 1)); printf 'not ok - %s\n' "$1"; }
assert() {
    local description=$1
    shift
    if "$@"; then ok "$description"; else not_ok "$description"; fi
}
assert_equal() {
    if [ "$2" = "$3" ]; then ok "$1"; else not_ok "$1 (expected: $2; got: $3)"; fi
}
expect_exit() {
    local want=$1 description=$2 got
    shift 2
    "$@" >"$OUT" 2>&1
    got=$?
    cat "$OUT" >>"$WORK/all-output.txt"
    if [ "$got" = "$want" ]; then ok "$description (exit $got)"; else not_ok "$description: wanted exit $want, got $got"; sed 's/^/    /' "$OUT"; fi
}

ops() { PATH="$WORK/bin:$PATH" MYSQL_HOST_NAME=$SAVED_HOST MYSQL_USERNAME=$SAVED_USER MYSQL_PASSWORD=$SAVED_PASSWORD MYSQL_DB_NAME=$SELFTEST_DB OPS_ROOT=$APP "$@"; }
backup() { ops "$OPS_DIR/backup.sh" --dir "$BACKUPS" "$@"; }
restore() { ops "$OPS_DIR/restore.sh" "$@"; }
newest() { ls -1 "$BACKUPS" | grep -E '^[0-9]{8}-[0-9]{6}$' | LC_ALL=C sort | tail -n 1; }
schemas_named() { sql -N -B -e "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name LIKE '$1'"; }
objects_of() { sql -N -B -e "SELECT (SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema = '$1'), (SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = '$1'), (SELECT COUNT(*) FROM information_schema.views WHERE table_schema = '$1')"; }
in_manifest() { grep -qP "$2" "$1/manifest.txt"; }
in_archive() { tar -tzf "$1/writable.tar.gz" | grep -q "$2"; }
in_dump() { gzip -dc "$1/database.sql.gz" | grep -q "$2"; }
files_hash_ok() {
    local hash size name
    while read -r hash size name; do
        [ "$(sha256_of "$1/$name")" = "$hash" ] || return 1
    done < <(manifest_section "$1" files)
}
data_intact() {
    [ "$(sql -N -B "$SELFTEST_DB" -e 'SELECT HEX(picture), price FROM items WHERE id = 1')" = "$(printf '00FF10AB7F0D0A00\t12.3456')" ] \
        && [ "$(sql -N -B "$SELFTEST_DB" -e 'SELECT name FROM items WHERE id = 3')" = 'صنف ثالث' ] \
        && [ "$(sql -N -B "$SELFTEST_DB" -e "SET time_zone = '+00:00'; SELECT created FROM items WHERE id = 2")" = '2026-10-02 23:59:59' ]
}

build_source() {
    sql -e "DROP DATABASE IF EXISTS \`$SELFTEST_DB\`; CREATE DATABASE \`$SELFTEST_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci" || return 1
    sql "$SELFTEST_DB" <<'SQL' || return 1
CREATE TABLE items (id INT PRIMARY KEY AUTO_INCREMENT, name VARCHAR(255) NOT NULL, price DECIMAL(15,4) NOT NULL, note MEDIUMTEXT, created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, picture BLOB) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE ledger (id INT PRIMARY KEY AUTO_INCREMENT, item_id INT NOT NULL, debit DECIMAL(15,4) NOT NULL DEFAULT 0, credit DECIMAL(15,4) NOT NULL DEFAULT 0, posted DATE NOT NULL, FOREIGN KEY (item_id) REFERENCES items (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE empty_table (id INT PRIMARY KEY) ENGINE=InnoDB;
SET time_zone = '+00:00';
INSERT INTO items (name, price, note, created, picture) VALUES
  ('صنف أول', 12.3456, 'ملاحظة بالعربية ✓ 😀', '2026-10-01 08:30:00', UNHEX('00FF10AB7F0D0A00')),
  ('Second item', 0.0001, NULL, '2026-10-02 23:59:59', NULL),
  ('صنف ثالث', 99999999999.9999, 'line1\nline2 ''quoted'' "double" \\ backslash', '2026-10-03 00:00:00', UNHEX('FFFEFDFC'));
INSERT INTO ledger (item_id, debit, credit, posted) VALUES (1, 10.5, 0, '2026-10-01'), (1, 0, 10.5, '2026-10-01'), (2, 7.25, 0, '2026-10-02');
CREATE VIEW priced AS SELECT id, name, price FROM items WHERE price > 1;
SQL
    sql --delimiter='$$' "$SELFTEST_DB" -e "CREATE FUNCTION doubled(x DECIMAL(15,4)) RETURNS DECIMAL(15,4) DETERMINISTIC BEGIN RETURN x * 2; END\$\$ CREATE TRIGGER items_name BEFORE INSERT ON items FOR EACH ROW BEGIN SET NEW.name = TRIM(NEW.name); END\$\$" || return 1
}

printf 'self-test of the backup and restore tools on %s (server %s)\n' "$SELFTEST_DB" "$(sql -N -B -e 'SELECT VERSION()')"

build_source || { not_ok "the source database was built"; exit 1; }
ok "the source database was built"

printf 'uploads one\n' >"$APP/writable/uploads/a.txt"
printf 'سند مرفق\n' >"$APP/writable/accounting_attachments/b.txt"
printf 'cache is not backed up\n' >"$APP/writable/cache/c.tmp"
printf 'log is not backed up\n' >"$APP/writable/logs/l.log"
if [[ "$SAVED_PASSWORD" != *"'"* ]]; then
    printf "CI_ENVIRONMENT = production\ndatabase.default.hostname = '%s'\ndatabase.default.database = '%s'\ndatabase.default.username = '%s'\ndatabase.default.password = '%s'\n" "$SAVED_HOST" "$SELFTEST_DB" "$SAVED_USER" "$SAVED_PASSWORD" >"$APP/.env"
    ENV_HAS_CREDENTIALS=1
else
    printf 'CI_ENVIRONMENT = production\n' >"$APP/.env"
    ENV_HAS_CREDENTIALS=0
fi
chmod 640 "$APP/.env"
cp -a "$APP/writable" "$WORK/writable.before"
BEFORE_CHECKSUMS=$(table_checksums "$SELFTEST_DB")
BEFORE_OBJECTS=$(objects_of "$SELFTEST_DB")

expect_exit 0 "a backup is made" backup --keep 5
FOLDER="$BACKUPS/$(newest)"

assert "the backup folder is private (0700)" test "$(stat -c %a "$FOLDER")" = 700
assert "every file of the backup is private (0600)" test -z "$(find "$FOLDER" -type f ! -perm 600)"
assert_equal "the folder holds the dump, the files, the copy of .env and the manifest" "database.sql.gz dot-env manifest.txt writable.tar.gz" "$(ls -1 "$FOLDER" | paste -sd' ' -)"
assert "no unfinished folder is left behind" test -z "$(ls -1A "$BACKUPS" | grep '^\.incomplete' || true)"
assert "the manifest names the time, the database and the git commit" bash -c "grep -q '^created=' '$FOLDER/manifest.txt' && grep -q '^database=$SELFTEST_DB\$' '$FOLDER/manifest.txt' && grep -q '^git_commit=' '$FOLDER/manifest.txt'"
assert "the manifest holds the SHA-256 of every file, and they match" files_hash_ok "$FOLDER"
assert "the manifest counts the rows of every table" bash -c "$(declare -f in_manifest); in_manifest '$FOLDER' '^items\t3\$' && in_manifest '$FOLDER' '^ledger\t3\$' && in_manifest '$FOLDER' '^empty_table\t0\$'"
assert_equal "the manifest holds a checksum for every table" 3 "$(manifest_section "$FOLDER" checksums | wc -l)"
assert "the archive holds uploads and attachments" bash -c "$(declare -f in_archive); in_archive '$FOLDER' 'writable/uploads/a.txt' && in_archive '$FOLDER' 'writable/accounting_attachments/b.txt'"
assert "the archive leaves out cache and logs" bash -c "$(declare -f in_archive); ! in_archive '$FOLDER' 'writable/cache/c.tmp' && ! in_archive '$FOLDER' 'writable/logs/l.log'"
assert "the copy of .env is the .env" cmp -s "$APP/.env" "$FOLDER/dot-env"
assert "the dump holds the routine and the trigger" bash -c "$(declare -f in_dump); in_dump '$FOLDER' 'FUNCTION.*doubled' && in_dump '$FOLDER' 'TRIGGER.*items_name'"
assert "the dump was made with --single-transaction --routines --triggers" grep -q -- '--single-transaction --routines --triggers' "$ARGV"

expect_exit 0 "the backup verifies" backup --verify
assert "the verification says the scratch copy is identical to the source" grep -q 'identical to' "$OUT"
assert_equal "the scratch database was dropped" 0 "$(schemas_named 'ospos_test_verify_%')"

sql "$SELFTEST_DB" -e "UPDATE items SET price = price + 1 WHERE id = 2"
expect_exit 0 "a verification after the source changed still passes" backup --verify
assert "and it names the table that changed" grep -q 'has changed since the backup in: items' "$OUT"
sql "$SELFTEST_DB" -e "UPDATE items SET price = price - 1 WHERE id = 2"

cp -a "$FOLDER" "$WORK/corrupt"
printf 'X' | dd of="$WORK/corrupt/database.sql.gz" bs=1 seek=30 conv=notrunc 2>/dev/null
expect_exit "$EXIT_VERIFY" "a corrupted dump is refused by verify" backup --verify "$WORK/corrupt"
expect_exit "$EXIT_VERIFY" "a corrupted dump is refused by restore before anything is written" restore --yes-empty-database "$RESTORED" "$WORK/corrupt"
assert_equal "the refused restore created nothing" 0 "$(schemas_named "$RESTORED")"

cp -a "$FOLDER" "$WORK/tampered"
sed -i -E "s/^(items\t)[0-9]+\$/\199/" "$WORK/tampered/manifest.txt"
expect_exit "$EXIT_VERIFY" "a table whose row count differs from the manifest fails the verification" backup --verify "$WORK/tampered"
assert "and the differing table is named" grep -q 'DIFFERS: row count of table items' "$OUT"
assert_equal "the scratch database of a failed verification was dropped too" 0 "$(schemas_named 'ospos_test_verify_%')"

expect_exit "$EXIT_USAGE" "restore without the explicit flag touches nothing" restore "$FOLDER"
expect_exit "$EXIT_NOT_EMPTY" "restore refuses a database that holds tables" restore --yes-empty-database "$SELFTEST_DB" "$FOLDER"
assert "the refusal says that the next migration would fail half way and to restore into an EMPTY database" grep -q 'next migration then fails half way.*EMPTY database' "$OUT"
assert_equal "the refused restore changed no table" "$BEFORE_CHECKSUMS" "$(table_checksums "$SELFTEST_DB")"
expect_exit "$EXIT_USAGE" "a retention below 1 is refused" backup --keep 0
expect_exit "$EXIT_USAGE" "an unknown option is refused" backup --nonsense

for number in 1 2 3 4 5; do
    OPS_BACKUP_STAMP="20300101-00000$number" backup --keep 3 >/dev/null 2>&1
done
assert_equal "retention keeps the newest 3 and removes the rest" "20300101-000003 20300101-000004 20300101-000005" "$(ls -1 "$BACKUPS" | paste -sd' ' -)"

LAST="$BACKUPS/$(newest)"
rm -rf "$APP/writable/uploads" "$APP/writable/accounting_attachments"
cp "$APP/.env" "$WORK/env.live"
sql -e "DROP DATABASE \`$SELFTEST_DB\`"
assert_equal "the source database is destroyed" 0 "$(schemas_named "$SELFTEST_DB")"

expect_exit 0 "the backup is restored into the destroyed database" restore --yes-empty-database "$SELFTEST_DB" "$LAST"
assert "the restore prints the readiness command to run" grep -q 'accounting:readiness' "$OUT"
assert_equal "every table has the checksum it had before the destroy" "$BEFORE_CHECKSUMS" "$(table_checksums "$SELFTEST_DB")"
assert_equal "the routine, the trigger and the view are back" "$BEFORE_OBJECTS" "$(objects_of "$SELFTEST_DB")"
assert "the data is intact (Arabic text, blob, decimals, timestamps)" data_intact
assert "the files of writable/ are back as they were" bash -c "diff -r '$WORK/writable.before/uploads' '$APP/writable/uploads' && diff -r '$WORK/writable.before/accounting_attachments' '$APP/writable/accounting_attachments'"
assert "the live .env was not overwritten" cmp -s "$APP/.env" "$WORK/env.live"
expect_exit "$EXIT_NOT_EMPTY" "a second restore into the same database is refused" restore --yes-empty-database "$SELFTEST_DB" "$LAST"

expect_exit 0 "a restore into a database that does not exist creates it" restore --yes-empty-database "$RESTORED" --no-files "$LAST"
assert_equal "the second copy has the same checksums" "$BEFORE_CHECKSUMS" "$(table_checksums "$RESTORED")"

if [ "$ENV_HAS_CREDENTIALS" = 1 ]; then
    expect_exit 0 "a backup reads the credentials from .env when the environment has none" env -u MYSQL_PASSWORD -u MYSQL_USERNAME -u MYSQL_HOST_NAME -u MYSQL_DB_NAME OPS_ROOT="$APP" "$OPS_DIR/backup.sh" --dir "$BACKUPS" --keep 5
else
    printf 'skipped - the .env path (the password holds a quote)\n'
fi

if [ -n "$SAVED_PASSWORD" ]; then
    assert "the password is in none of the output of the tools" bash -c "! grep -qF -- \"\$1\" '$WORK/all-output.txt'" _ "$SAVED_PASSWORD"
    assert "the password was on no command line of mysql or mysqldump" bash -c "! grep -qF -- \"\$1\" '$ARGV'" _ "$SAVED_PASSWORD"
fi
assert "the clients were given an option file instead of a password" grep -q -- '--defaults-extra-file=' "$ARGV"
assert "no temporary option file is left behind" test -z "$(ls -d "${TMPDIR:-/tmp}"/ospos-ops.* 2>/dev/null | grep -v "^$OPS_TMP\$" || true)"

printf '\n%d passed, %d failed\n' "$PASSED" "$FAILED"
[ "$FAILED" = 0 ]
