#!/usr/bin/env bash
# Helpers shared by backup.sh, restore.sh and selftest.sh. Sourced, never run.
#
# Credentials come from the environment (MYSQL_HOST_NAME, MYSQL_USERNAME, MYSQL_PASSWORD,
# MYSQL_DB_NAME, optionally MYSQL_PORT) or from the .env file of the application, the way
# app/Config/Database.php reads them. They are never printed and never put on a command line:
# the clients read them from a temporary option file that only its owner can open.

OPS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OPS_ROOT="${OPS_ROOT:-$(cd "$OPS_DIR/../.." && pwd)}"

EXIT_USAGE=2
EXIT_CREDENTIALS=3
EXIT_DUMP=4
EXIT_FILES=5
EXIT_VERIFY=6
EXIT_SCRATCH=7
EXIT_NOT_EMPTY=8
EXIT_RESTORE=9

OPS_TMP=""
OPS_DEFAULTS=""

log() { printf '%s\n' "$*" >&2; }
die() {
    local code=$1
    shift
    log "ERROR: $*"
    exit "$code"
}

cleanup_tmp() {
    if [ -n "$OPS_TMP" ] && [ -d "$OPS_TMP" ]; then
        rm -rf -- "$OPS_TMP"
    fi
}

valid_name() { [[ "$1" =~ ^[A-Za-z0-9_]+$ ]]; }

dotenv_value() {
    local key=$1 file="$OPS_ROOT/.env"
    [ -r "$file" ] || return 1
    awk -v k="$key" '
        { line = $0; sub(/^[ \t]+/, "", line) }
        line ~ /^#/ { next }
        {
            n = index(line, "=")
            if (n == 0) next
            name = substr(line, 1, n - 1)
            gsub(/[ \t]+$/, "", name)
            if (name == k) {
                v = substr(line, n + 1)
                sub(/^[ \t]+/, "", v)
                sub(/[ \t\r]+$/, "", v)
                if (v ~ /^".*"$/ || v ~ /^\047.*\047$/) v = substr(v, 2, length(v) - 2)
                val = v
                found = 1
            }
        }
        END { if (found) { print val; exit 0 } exit 1 }
    ' "$file"
}

env_group() {
    local environment=${CI_ENVIRONMENT:-}
    [ -n "$environment" ] || environment=$(dotenv_value CI_ENVIRONMENT || true)
    case "$environment" in
        development) printf 'development' ;;
        testing) printf 'tests' ;;
        *) printf 'default' ;;
    esac
}

load_credentials() {
    local group needs_database=${1:-database}
    group=$(env_group)

    DB_HOST=${MYSQL_HOST_NAME:-$(dotenv_value "database.$group.hostname" || true)}
    DB_USER=${MYSQL_USERNAME:-$(dotenv_value "database.$group.username" || true)}
    DB_NAME=${MYSQL_DB_NAME:-$(dotenv_value "database.$group.database" || true)}
    DB_PORT=${MYSQL_PORT:-$(dotenv_value "database.$group.port" || true)}

    if [ -n "${MYSQL_PASSWORD:-}" ]; then
        DB_PASS=$MYSQL_PASSWORD
    else
        DB_PASS=$(dotenv_value "database.$group.password" || true)
    fi

    DB_HOST=${DB_HOST:-localhost}
    DB_PORT=${DB_PORT:-3306}

    [ -n "$DB_USER" ] || die "$EXIT_CREDENTIALS" "no database user: set MYSQL_USERNAME or database.$group.username in $OPS_ROOT/.env"
    if [ "$needs_database" = database ]; then
        [ -n "$DB_NAME" ] || die "$EXIT_CREDENTIALS" "no database name: set MYSQL_DB_NAME or database.$group.database in $OPS_ROOT/.env"
    fi
    [ -z "$DB_NAME" ] || valid_name "$DB_NAME" || die "$EXIT_CREDENTIALS" "the database name may hold letters, digits and underscores only"

    MYSQL_BIN=$(command -v mysql || command -v mariadb || true)
    DUMP_BIN=$(command -v mysqldump || command -v mariadb-dump || true)
    [ -n "$MYSQL_BIN" ] || die "$EXIT_CREDENTIALS" "the mysql client is not installed"
    [ -n "$DUMP_BIN" ] || die "$EXIT_CREDENTIALS" "mysqldump is not installed"

    local password=${DB_PASS//\\/\\\\}
    password=${password//\"/\\\"}

    OPS_TMP=$(mktemp -d "${TMPDIR:-/tmp}/ospos-ops.XXXXXX")
    chmod 700 "$OPS_TMP"
    OPS_DEFAULTS="$OPS_TMP/client.cnf"
    (
        umask 077
        printf '[client]\nhost=%s\nport=%s\nuser=%s\npassword="%s"\ndefault-character-set=utf8mb4\n' "$DB_HOST" "$DB_PORT" "$DB_USER" "$password" >"$OPS_DEFAULTS"
    )
    chmod 600 "$OPS_DEFAULTS"
    unset password DB_PASS MYSQL_PASSWORD

    trap cleanup_tmp EXIT

    sql -N -B -e 'SELECT 1' >/dev/null 2>&1 || die "$EXIT_CREDENTIALS" "cannot connect to the database server as $DB_USER at $DB_HOST:$DB_PORT"
}

sql() { "$MYSQL_BIN" --defaults-extra-file="$OPS_DEFAULTS" "$@"; }
dump() { "$DUMP_BIN" --defaults-extra-file="$OPS_DEFAULTS" "$@"; }

db_exists() { [ "$(sql -N -B -e "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = '$1'")" = 1 ]; }

db_object_count() {
    sql -N -B -e "SELECT (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$1')
        + (SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema = '$1')
        + (SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = '$1')
        + (SELECT COUNT(*) FROM information_schema.events WHERE event_schema = '$1')"
}

db_charset() { sql -N -B -e "SELECT CONCAT(default_character_set_name, ' ', default_collation_name) FROM information_schema.schemata WHERE schema_name = '$1'"; }

create_database() {
    local name=$1 charset=${2:-utf8mb4} collation=${3:-utf8mb4_general_ci}
    sql -e "CREATE DATABASE \`$name\` CHARACTER SET $charset COLLATE $collation"
}

table_counts() {
    sql -N -B "$1" -e "SET SESSION group_concat_max_len = 4194304;
        SELECT GROUP_CONCAT(CONCAT('SELECT ''', table_name, ''' AS t, COUNT(*) AS c FROM \`', table_name, '\`') SEPARATOR ' UNION ALL ') INTO @q
        FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE';
        PREPARE s FROM IFNULL(@q, 'SELECT 1 WHERE 1 = 0');
        EXECUTE s;
        DEALLOCATE PREPARE s" | LC_ALL=C sort
}

table_checksums() {
    local names list
    names=$(sql -N -B -e "SELECT table_name FROM information_schema.tables WHERE table_schema = '$1' AND table_type = 'BASE TABLE' ORDER BY table_name")
    [ -n "$names" ] || return 0
    list=$(printf '%s\n' "$names" | sed 's/.*/`&`/' | paste -sd, -)
    sql -N -B "$1" -e "CHECKSUM TABLE $list" | awk -F'\t' '{ sub(/^[^.]*\./, "", $1); print $1 "\t" $2 }' | LC_ALL=C sort
}

sha256_of() {
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum -- "$1" | cut -d' ' -f1
    else
        shasum -a 256 -- "$1" | cut -d' ' -f1
    fi
}

manifest_value() { sed -n "s/^$2=//p" "$1/manifest.txt" | head -n1; }

manifest_section() {
    awk -v s="[$2]" '$0 == s { on = 1; next } /^\[/ { on = 0 } on && NF { print }' "$1/manifest.txt"
}

check_backup_files() {
    local folder=$1 hash size name bad=0
    [ -r "$folder/manifest.txt" ] || die "$EXIT_VERIFY" "$folder has no manifest.txt: it is not a backup folder"

    while read -r hash size name; do
        [ -n "$name" ] || continue
        if [ ! -f "$folder/$name" ]; then
            log "MISSING: $name"
            bad=1
        elif [ "$(sha256_of "$folder/$name")" != "$hash" ]; then
            log "CHANGED: $name does not match the SHA-256 in the manifest"
            bad=1
        fi
    done < <(manifest_section "$folder" files)

    return "$bad"
}

compare_to_manifest() {
    local folder=$1 database=$2 differences=0 expected actual
    expected=$(manifest_section "$folder" checksums)
    actual=$(table_checksums "$database")

    if [ "$expected" != "$actual" ]; then
        differences=1
        { diff <(printf '%s\n' "$expected") <(printf '%s\n' "$actual") || true; } | sed -n 's/^[<>] \([^\t]*\)\t.*/DIFFERS: table \1/p' | sort -u >&2
    fi

    expected=$(manifest_section "$folder" rows)
    actual=$(table_counts "$database")

    if [ "$expected" != "$actual" ]; then
        differences=1
        { diff <(printf '%s\n' "$expected") <(printf '%s\n' "$actual") || true; } | sed -n 's/^[<>] \([^\t]*\)\t.*/DIFFERS: row count of table \1/p' | sort -u >&2
    fi

    return "$differences"
}
