#!/usr/bin/env bash
# Restores a backup made by backup.sh into an EMPTY database and puts the files of writable/ back.
# It refuses to restore into a database that holds anything: a dump restored over the live database
# leaves the tables a newer release created, with their rows. Reads the credentials the way lib.sh
# describes and never prints them.
#
#   tools/ops/restore.sh --yes-empty-database NAME FOLDER [--no-files]
#
# Exit codes: 0 done, 2 wrong usage or no confirmation, 3 no credentials or no connection, 5 the
# files could not be put back, 6 the backup does not match its manifest (before or after the
# restore), 7 the database could not be made, 8 the database is not empty, 9 the restore failed,
# 1 anything else.

set -euo pipefail

# shellcheck source=tools/ops/lib.sh
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

usage() {
    cat <<'USAGE'
Usage:
  restore.sh --yes-empty-database NAME FOLDER [--no-files]

  FOLDER                      A dated folder made by backup.sh.
  --yes-empty-database NAME   Restore into the database NAME, which must not exist or must hold no
                              table, view, routine, trigger or event. NAME is written out on purpose:
                              without it nothing is touched. To roll back a failed release drop and
                              create the live database first (runbook step 8), stop the site, then run this.
  --no-files                  Restore the database only; leave writable/ as it is.

The SHA-256 of every file is checked against the manifest before anything is written, and the
checksum and row count of every restored table are checked against it afterwards. The copy of .env
in the backup is NOT put over the live .env: it stays in the folder for you to compare.

The credentials come from MYSQL_HOST_NAME, MYSQL_USERNAME, MYSQL_PASSWORD or from the .env file of the application.
USAGE
}

TARGET=""
FOLDER=""
FILES=1

while [ $# -gt 0 ]; do
    case "$1" in
        --yes-empty-database) [ $# -ge 2 ] || die "$EXIT_USAGE" "--yes-empty-database needs the name of the database"; TARGET=$2; shift 2 ;;
        --no-files) FILES=0; shift ;;
        -h | --help) usage; exit 0 ;;
        -*) usage >&2; die "$EXIT_USAGE" "unknown option $1" ;;
        *) [ -z "$FOLDER" ] || die "$EXIT_USAGE" "one folder only"; FOLDER=${1%/}; shift ;;
    esac
done

[ -n "$FOLDER" ] || { usage >&2; die "$EXIT_USAGE" "name the backup folder to restore"; }
[ -n "$TARGET" ] || { usage >&2; die "$EXIT_USAGE" "nothing was touched: say which database to restore into with --yes-empty-database NAME"; }
valid_name "$TARGET" || die "$EXIT_USAGE" "the database name may hold letters, digits and underscores only"
[ -d "$FOLDER" ] || die "$EXIT_USAGE" "$FOLDER is not a folder"

load_credentials no-database

check_backup_files "$FOLDER" || die "$EXIT_VERIFY" "the files of $FOLDER do not match its manifest: nothing was restored"
log "restore: SHA-256 of every file matches the manifest"

if db_exists "$TARGET"; then
    objects=$(db_object_count "$TARGET")
    if [ "$objects" != 0 ]; then
        die "$EXIT_NOT_EMPTY" "refusing: the database $TARGET holds $objects object(s). A dump restored over tables that a newer release created leaves their rows, and the next migration then fails half way (seen on a copy: migration 59, Unknown column invoice_date, 58 of 116 applied). Restore into an EMPTY database: keep what it holds if it matters (mysqldump), then DROP and CREATE it (runbook step 8). Nothing was changed."
    fi
    log "restore: the database $TARGET exists and is empty"
else
    charset=$(manifest_value "$FOLDER" db_charset)
    collation=$(manifest_value "$FOLDER" db_collation)
    create_database "$TARGET" "${charset:-utf8mb4}" "${collation:-utf8mb4_general_ci}" || die "$EXIT_SCRATCH" "cannot create the database $TARGET (the database user needs the right to create it)"
    log "restore: created the database $TARGET"
fi

if ! gzip -dc "$FOLDER/database.sql.gz" | sql "$TARGET"; then
    die "$EXIT_RESTORE" "the dump did not load into $TARGET: drop and create it again before another try"
fi
log "restore: the dump loaded into $TARGET"

if compare_to_manifest "$FOLDER" "$TARGET"; then
    log "restore: every table of $TARGET has the checksum and the row count the manifest recorded"
else
    die "$EXIT_VERIFY" "the restored database differs from what the manifest recorded"
fi

if [ "$FILES" = 1 ]; then
    if [ -f "$FOLDER/writable.tar.gz" ]; then
        mkdir -p "$OPS_ROOT"
        tar -C "$OPS_ROOT" -xzf "$FOLDER/writable.tar.gz" || die "$EXIT_FILES" "the files of writable/ could not be put back"
        log "restore: writable/ put back under $OPS_ROOT"
    fi
else
    log "restore: --no-files: writable/ left as it is"
fi

if [ -f "$FOLDER/dot-env" ]; then
    log "restore: the .env of the backup was not copied: it is $FOLDER/dot-env (mode 0600); compare it with the live .env"
fi

cat <<NEXT
restore: done. Put the code back at commit $(manifest_value "$FOLDER" git_commit) if this is a rollback, then check the restored database:
  MYSQL_DB_NAME=$TARGET php spark accounting:readiness
  MYSQL_DB_NAME=$TARGET php spark accounting:readiness --json --no-header > readiness-restored.json
NEXT
