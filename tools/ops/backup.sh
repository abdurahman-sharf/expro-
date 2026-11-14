#!/usr/bin/env bash
# Backs up the database and the files of the application into a dated folder, and verifies a backup
# by restoring it into a scratch database. Reads the credentials the way lib.sh describes and never
# prints them.
#
#   tools/ops/backup.sh [--dir DIR] [--keep N] [--verify-after]
#   tools/ops/backup.sh --verify [FOLDER] [--dir DIR] [--scratch NAME] [--keep-scratch]
#
# Exit codes: 0 done, 2 wrong usage, 3 no credentials or no connection, 4 the dump failed, 5 the
# files, the copy of .env or the manifest could not be written, 6 a file or a table differs from
# the manifest, 7 the scratch database could not be made, 1 anything else.

set -euo pipefail

# shellcheck source=tools/ops/lib.sh
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

usage() {
    cat <<'USAGE'
Usage:
  backup.sh [--dir DIR] [--keep N] [--verify-after]
      Back up the database (mysqldump --single-transaction --routines --triggers), writable/,
      and a copy of .env (mode 0600) into DIR/YYYYMMDD-HHMMSS (UTC), with a manifest of the time,
      the git commit, the row count and checksum of every table and the SHA-256 of every file.
      The folder appears whole or not at all. Then only the newest N folders are kept.
  backup.sh --verify [FOLDER] [--dir DIR] [--scratch NAME] [--keep-scratch]
      Check the SHA-256 of every file, restore the dump into a scratch database (default name
      ospos_test_verify_<time>), compare CHECKSUM TABLE and the row count of every table with the
      manifest and with the database now, and drop the scratch database. FOLDER defaults to the
      newest backup in DIR.

Options:
  --dir DIR         Where the dated folders live. Default: $OPS_BACKUP_DIR, else <app>/backups.
  --keep N          How many backups to keep (default 14, at least 1).
  --verify-after    Verify the backup that was just made.
  --scratch NAME    Name of the scratch database for --verify. It must not exist.
  --keep-scratch    Leave the scratch database, for a rehearsal with php spark accounting:readiness.

The database comes from MYSQL_HOST_NAME, MYSQL_USERNAME, MYSQL_PASSWORD and MYSQL_DB_NAME, or from the
.env file of the application. The password never appears on a command line or in any output.
USAGE
}

BACKUP_DIR=${OPS_BACKUP_DIR:-$OPS_ROOT/backups}
KEEP=14
MODE=backup
VERIFY_AFTER=0
VERIFY_FOLDER=""
SCRATCH=""
KEEP_SCRATCH=0

while [ $# -gt 0 ]; do
    case "$1" in
        --dir) [ $# -ge 2 ] || die "$EXIT_USAGE" "--dir needs a folder"; BACKUP_DIR=$2; shift 2 ;;
        --keep) [ $# -ge 2 ] || die "$EXIT_USAGE" "--keep needs a number"; KEEP=$2; shift 2 ;;
        --verify-after) VERIFY_AFTER=1; shift ;;
        --verify) MODE=verify; shift ;;
        --scratch) [ $# -ge 2 ] || die "$EXIT_USAGE" "--scratch needs a database name"; SCRATCH=$2; shift 2 ;;
        --keep-scratch) KEEP_SCRATCH=1; shift ;;
        -h | --help) usage; exit 0 ;;
        -*) usage >&2; die "$EXIT_USAGE" "unknown option $1" ;;
        *) [ -z "$VERIFY_FOLDER" ] || die "$EXIT_USAGE" "one folder only"; VERIFY_FOLDER=$1; shift ;;
    esac
done

[[ "$KEEP" =~ ^[1-9][0-9]*$ ]] || die "$EXIT_USAGE" "--keep must be a whole number of at least 1"

newest_backup() {
    local newest
    newest=$(ls -1 "$BACKUP_DIR" 2>/dev/null | grep -E '^[0-9]{8}-[0-9]{6}$' | LC_ALL=C sort | tail -n 1 || true)
    [ -n "$newest" ] || die "$EXIT_FILES" "no backup in $BACKUP_DIR"
    printf '%s/%s' "$BACKUP_DIR" "$newest"
}

SCRATCH_NAME=""
SCRATCH_CREATED=0

drop_scratch() {
    if [ "$SCRATCH_CREATED" = 1 ] && [ "$KEEP_SCRATCH" = 0 ]; then
        sql -e "DROP DATABASE IF EXISTS \`$SCRATCH_NAME\`" || log "WARNING: could not drop the scratch database $SCRATCH_NAME"
    fi
    SCRATCH_CREATED=0
}

verify_backup() {
    local folder=$1 scratch=${2:-} charset collation status=0 live_differs=""

    log "verify: $folder"
    check_backup_files "$folder" || die "$EXIT_VERIFY" "the files of $folder do not match its manifest"
    log "verify: SHA-256 of every file matches the manifest"

    [ -n "$scratch" ] || scratch="ospos_test_verify_$(date -u +%Y%m%d%H%M%S)_$$"
    valid_name "$scratch" || die "$EXIT_USAGE" "the scratch name may hold letters, digits and underscores only"
    [ "$scratch" != "$DB_NAME" ] || die "$EXIT_USAGE" "the scratch database cannot be the database of the application"
    if db_exists "$scratch"; then
        die "$EXIT_SCRATCH" "the scratch database $scratch exists already: name another or drop it"
    fi

    charset=$(manifest_value "$folder" db_charset)
    collation=$(manifest_value "$folder" db_collation)
    create_database "$scratch" "${charset:-utf8mb4}" "${collation:-utf8mb4_general_ci}" || die "$EXIT_SCRATCH" "cannot create the scratch database $scratch (the database user needs the right to create it)"
    SCRATCH_NAME=$scratch
    SCRATCH_CREATED=1
    trap 'drop_scratch; cleanup_tmp' EXIT

    if ! gzip -dc "$folder/database.sql.gz" | sql "$scratch"; then
        die "$EXIT_VERIFY" "the dump does not load into the scratch database $scratch"
    fi
    log "verify: the dump loaded into $scratch"

    if compare_to_manifest "$folder" "$scratch"; then
        log "verify: every table of $scratch has the checksum and the row count the manifest recorded"
    else
        status=1
    fi

    if db_exists "$DB_NAME"; then
        local source_checks scratch_checks
        source_checks=$(table_checksums "$DB_NAME")
        scratch_checks=$(table_checksums "$scratch")
        if [ "$source_checks" = "$scratch_checks" ]; then
            log "verify: every table of the scratch copy is identical to $DB_NAME now ($(printf '%s\n' "$scratch_checks" | grep -c . || true) tables)"
        else
            live_differs=$({ diff <(printf '%s\n' "$source_checks") <(printf '%s\n' "$scratch_checks") || true; } | sed -n 's/^[<>] \([^\t]*\)\t.*/\1/p' | sort -u | paste -sd, -)
            log "verify: WARNING: $DB_NAME has changed since the backup in: $live_differs (expected when the shop was open after the dump)"
        fi
    fi

    if [ "$KEEP_SCRATCH" = 1 ]; then
        log "verify: the scratch database $scratch is kept: MYSQL_DB_NAME=$scratch php spark accounting:readiness"
    fi

    if [ "$status" != 0 ]; then
        die "$EXIT_VERIFY" "the restored copy differs from what the manifest recorded"
    fi

    drop_scratch
    log "verify: OK"
}

make_backup() {
    local stamp target tmp git_commit git_state hash size name checks_file
    stamp=${OPS_BACKUP_STAMP:-$(date -u +%Y%m%d-%H%M%S)}
    [[ "$stamp" =~ ^[0-9]{8}-[0-9]{6}$ ]] || die "$EXIT_USAGE" "OPS_BACKUP_STAMP must look like YYYYMMDD-HHMMSS"

    mkdir -p "$BACKUP_DIR"
    chmod 700 "$BACKUP_DIR"
    target="$BACKUP_DIR/$stamp"
    [ ! -e "$target" ] || die "$EXIT_FILES" "$target exists already"

    tmp="$BACKUP_DIR/.incomplete-$stamp-$$"
    umask 077
    mkdir "$tmp"
    trap "rm -rf -- '$tmp'; cleanup_tmp" EXIT

    db_exists "$DB_NAME" || die "$EXIT_CREDENTIALS" "the database $DB_NAME does not exist"

    table_checksums "$DB_NAME" >"$tmp/checksums.part"
    table_counts "$DB_NAME" >"$tmp/rows.part"

    if ! dump --single-transaction --routines --triggers --hex-blob "$DB_NAME" | gzip -c >"$tmp/database.sql.gz"; then
        die "$EXIT_DUMP" "mysqldump failed: nothing was kept"
    fi
    [ -s "$tmp/database.sql.gz" ] || die "$EXIT_DUMP" "the dump is empty"
    gzip -t "$tmp/database.sql.gz" || die "$EXIT_DUMP" "the dump is not a complete gzip file"

    if [ -d "$OPS_ROOT/writable" ]; then
        tar -C "$OPS_ROOT" --warning=no-file-changed --exclude='writable/cache' --exclude='writable/session' --exclude='writable/debugbar' --exclude='writable/logs' -czf "$tmp/writable.tar.gz" writable || die "$EXIT_FILES" "tar of writable/ failed"
    else
        log "WARNING: $OPS_ROOT/writable does not exist: the archive of the files is empty"
        tar -czf "$tmp/writable.tar.gz" --files-from /dev/null || die "$EXIT_FILES" "cannot write the archive of the files"
    fi

    if [ -f "$OPS_ROOT/.env" ]; then
        cp "$OPS_ROOT/.env" "$tmp/dot-env" || die "$EXIT_FILES" "cannot copy .env"
        chmod 600 "$tmp/dot-env"
    else
        log "WARNING: $OPS_ROOT/.env does not exist (the application is configured by the environment): no copy of it was made"
    fi

    git_commit=$(git -C "$OPS_ROOT" rev-parse HEAD 2>/dev/null || printf 'unknown')
    git_state=clean
    if [ "$git_commit" != unknown ] && ! git -C "$OPS_ROOT" diff --quiet HEAD -- 2>/dev/null; then
        git_state=modified
    fi

    {
        printf '# Expro backup manifest, format 1\n'
        printf 'created=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
        printf 'stamp=%s\n' "$stamp"
        printf 'database=%s\n' "$DB_NAME"
        printf 'db_charset=%s\n' "$(db_charset "$DB_NAME" | cut -d' ' -f1)"
        printf 'db_collation=%s\n' "$(db_charset "$DB_NAME" | cut -d' ' -f2)"
        printf 'server_version=%s\n' "$(sql -N -B -e 'SELECT VERSION()')"
        printf 'git_commit=%s\n' "$git_commit"
        printf 'git_tree=%s\n' "$git_state"
        printf 'tables=%s\n' "$(grep -c . "$tmp/rows.part" || true)"
        printf '[files]\n'
        for name in database.sql.gz writable.tar.gz dot-env; do
            [ -f "$tmp/$name" ] || continue
            hash=$(sha256_of "$tmp/$name")
            size=$(wc -c <"$tmp/$name" | tr -d ' ')
            printf '%s %s %s\n' "$hash" "$size" "$name"
        done
        printf '[rows]\n'
        cat "$tmp/rows.part"
        printf '[checksums]\n'
        cat "$tmp/checksums.part"
    } >"$tmp/manifest.txt.new" || die "$EXIT_FILES" "cannot write the manifest"
    rm -f "$tmp/checksums.part" "$tmp/rows.part"
    mv "$tmp/manifest.txt.new" "$tmp/manifest.txt"
    chmod 600 "$tmp"/*
    chmod 700 "$tmp"

    sync 2>/dev/null || true
    mv "$tmp" "$target" || die "$EXIT_FILES" "cannot put the backup in place"
    trap cleanup_tmp EXIT

    log "backup: $target ($(wc -c <"$target/database.sql.gz" | tr -d ' ') bytes of dump, $(wc -c <"$target/writable.tar.gz" | tr -d ' ') bytes of files, $(grep -c . <<<"$(manifest_section "$target" rows)" || true) tables)"

    prune_backups
    BACKUP_MADE=$target
}

prune_backups() {
    local old
    while IFS= read -r old; do
        [ -n "$old" ] || continue
        rm -rf -- "${BACKUP_DIR:?}/$old"
        log "retention: removed $old (keeping the newest $KEEP)"
    done < <(ls -1 "$BACKUP_DIR" | grep -E '^[0-9]{8}-[0-9]{6}$' | LC_ALL=C sort -r | tail -n +"$((KEEP + 1))")
}

load_credentials

BACKUP_MADE=""

if [ "$MODE" = verify ]; then
    [ -n "$VERIFY_FOLDER" ] || VERIFY_FOLDER=$(newest_backup)
    verify_backup "${VERIFY_FOLDER%/}" "$SCRATCH"
    exit 0
fi

make_backup

if [ "$VERIFY_AFTER" = 1 ]; then
    verify_backup "$BACKUP_MADE" "$SCRATCH"
fi
