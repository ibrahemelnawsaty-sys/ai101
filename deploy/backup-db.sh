#!/bin/sh
# =============================================================================
# Athar Platform - daily MySQL backup
# =============================================================================
# Runs from cron, once a day. See deploy/backup.md and deploy/cron-setup.txt.
#
# Requires a MySQL option file so the password never appears in the process
# list. Create it ONCE, by hand, before the first run:
#
#   umask 077
#   cat > "$HOME/.my.athar.cnf" <<'EOF'
#   [client]
#   host=127.0.0.1
#   user=<prefix>_athar_app
#   password=<the password from the password manager>
#   EOF
#   chmod 600 "$HOME/.my.athar.cnf"
#
# @see CONSTITUTION.md Article 8 (audit_logs), Article 10 (no daemons)
# @see PRD section 12.7 (daily DB backup retained 30 days)
# =============================================================================

set -eu

# ----------------------------------------------------------------------------
# Configuration - edit these three values to match the account
# ----------------------------------------------------------------------------
APP_DIR="${ATHAR_APP_DIR:-$HOME/athar-app}"
BACKUP_DIR="${ATHAR_BACKUP_DIR:-$HOME/backups/db}"
DB_NAME="${ATHAR_DB_NAME:-}"          # leave empty to read it from .env

MYSQL_CNF="$HOME/.my.athar.cnf"
RETENTION_DAYS=30
MIN_KEPT=7                            # never prune below this many backups
MIN_FREE_MB=200                       # refuse to dump with less free space
MIN_DUMP_BYTES=10240                  # a smaller dump than this is suspect

umask 077

log() {
    # Timestamps in UTC. The application's own clock service is not available
    # to a shell script; UTC matches the database's storage timezone.
    printf '%s [backup-db] %s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$*"
}

fail() {
    log "FAILED: $*"
    exit 1
}

# ----------------------------------------------------------------------------
# Pre-flight
# ----------------------------------------------------------------------------
command -v mysqldump >/dev/null 2>&1 || fail "mysqldump not found in PATH"
command -v gzip      >/dev/null 2>&1 || fail "gzip not found in PATH"

[ -d "$APP_DIR" ] || fail "application directory not found: $APP_DIR"
[ -f "$MYSQL_CNF" ] || fail "missing MySQL option file: $MYSQL_CNF (see the header of this script)"

# The option file holds a password. Refuse to run if it is readable by anyone else.
CNF_MODE="$(stat -c '%a' "$MYSQL_CNF" 2>/dev/null || echo '')"
case "$CNF_MODE" in
    600|400) : ;;
    '')      log "WARNING: cannot stat $MYSQL_CNF permissions on this system" ;;
    *)       fail "$MYSQL_CNF has mode $CNF_MODE; it must be 600" ;;
esac

# Resolve the database name from .env when it was not supplied explicitly.
if [ -z "$DB_NAME" ]; then
    [ -f "$APP_DIR/.env" ] || fail "no DB name given and $APP_DIR/.env not found"
    DB_NAME="$(sed -n 's/^[[:space:]]*DB_DATABASE[[:space:]]*=[[:space:]]*//p' "$APP_DIR/.env" \
               | head -n 1 | tr -d "\"'\r")"
fi
[ -n "$DB_NAME" ] || fail "could not determine the database name"

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

# Free space check. A dump that runs out of disk produces a truncated file that
# looks like a backup until the day you need it.
FREE_MB="$(df -Pm "$BACKUP_DIR" | awk 'NR==2 {print $4}')"
if [ -n "$FREE_MB" ] && [ "$FREE_MB" -lt "$MIN_FREE_MB" ]; then
    fail "only ${FREE_MB}MB free in $BACKUP_DIR; need at least ${MIN_FREE_MB}MB"
fi

# ----------------------------------------------------------------------------
# Dump
# ----------------------------------------------------------------------------
STAMP="$(date -u '+%Y%m%d-%H%M%S')"
TARGET="$BACKUP_DIR/athar-${DB_NAME}-${STAMP}.sql.gz"
TMP="$TARGET.partial"

log "starting dump of '$DB_NAME' -> $TARGET"

# --single-transaction : consistent snapshot without locking InnoDB tables
# --quick              : stream rows instead of buffering the whole table
# --routines --triggers: the audit_logs immutability triggers MUST be captured,
#                        otherwise a restore silently drops Article 8 enforcement
# --no-tablespaces     : avoids needing the PROCESS privilege, unavailable on
#                        shared hosting
# --hex-blob           : safe round-trip for binary columns
# --default-character-set=utf8mb4 : Arabic content must survive the round trip
if ! mysqldump --defaults-extra-file="$MYSQL_CNF" \
        --single-transaction \
        --quick \
        --routines \
        --triggers \
        --events \
        --no-tablespaces \
        --hex-blob \
        --skip-lock-tables \
        --default-character-set=utf8mb4 \
        "$DB_NAME" 2>"$TMP.err" | gzip -9 > "$TMP"; then
    log "mysqldump stderr: $(cat "$TMP.err" 2>/dev/null || true)"
    rm -f "$TMP" "$TMP.err"
    fail "mysqldump exited non-zero"
fi

# mysqldump can exit 0 and still have written a warning worth reading.
if [ -s "$TMP.err" ]; then
    log "mysqldump warnings: $(tr '\n' ' ' < "$TMP.err")"
fi
rm -f "$TMP.err"

# ----------------------------------------------------------------------------
# Verify before promoting the file
# ----------------------------------------------------------------------------
SIZE="$(wc -c < "$TMP" | tr -d ' ')"
[ "$SIZE" -ge "$MIN_DUMP_BYTES" ] || { rm -f "$TMP"; fail "dump is only ${SIZE} bytes; refusing to keep it"; }

gzip -t "$TMP" 2>/dev/null || { rm -f "$TMP"; fail "gzip integrity check failed"; }

# mysqldump writes this marker as the last line of a complete dump. Its absence
# means the dump was cut short even though the exit code was zero.
if ! gzip -cd "$TMP" | tail -n 5 | grep -q 'Dump completed'; then
    rm -f "$TMP"
    fail "dump is missing the 'Dump completed' marker; it is truncated"
fi

# Sanity: the schema must actually be in there.
if ! gzip -cd "$TMP" | grep -q 'CREATE TABLE'; then
    rm -f "$TMP"
    fail "dump contains no CREATE TABLE statement"
fi

mv "$TMP" "$TARGET"
chmod 600 "$TARGET"

# Checksum manifest, so tampering or bit rot is detectable at restore time.
if command -v sha256sum >/dev/null 2>&1; then
    ( cd "$BACKUP_DIR" && sha256sum "$(basename "$TARGET")" >> "$BACKUP_DIR/SHA256SUMS" )
    chmod 600 "$BACKUP_DIR/SHA256SUMS"
fi

log "ok: $(basename "$TARGET") ($(wc -c < "$TARGET" | tr -d ' ') bytes)"

# ----------------------------------------------------------------------------
# Retention - 30 days (PRD section 12.7)
# ----------------------------------------------------------------------------
# Guard: never prune while fewer than MIN_KEPT backups exist. A misconfigured
# clock or a run of failures must not leave the account with zero backups.
COUNT="$(find "$BACKUP_DIR" -maxdepth 1 -name 'athar-*.sql.gz' -type f | wc -l | tr -d ' ')"

if [ "$COUNT" -gt "$MIN_KEPT" ]; then
    # Deleting one at a time keeps the count guard meaningful.
    find "$BACKUP_DIR" -maxdepth 1 -name 'athar-*.sql.gz' -type f -mtime "+$RETENTION_DAYS" -print \
    | while IFS= read -r old; do
        REMAINING="$(find "$BACKUP_DIR" -maxdepth 1 -name 'athar-*.sql.gz' -type f | wc -l | tr -d ' ')"
        if [ "$REMAINING" -gt "$MIN_KEPT" ]; then
            rm -f "$old"
            log "pruned $(basename "$old")"
        fi
    done
else
    log "retention skipped: only $COUNT backup(s) on disk, minimum kept is $MIN_KEPT"
fi

log "done. $(find "$BACKUP_DIR" -maxdepth 1 -name 'athar-*.sql.gz' -type f | wc -l | tr -d ' ') backup(s) retained."
exit 0
