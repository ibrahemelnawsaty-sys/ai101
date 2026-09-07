#!/bin/sh
# =============================================================================
# Athar Platform - weekly uploaded-files backup
# =============================================================================
# Runs from cron, once a week. See deploy/backup.md and deploy/cron-setup.txt.
#
# Backs up storage/app - participant submissions, project files, avatars and
# generated certificates. These files are NOT in version control and are NOT in
# the database dump; losing them loses a cohort's work.
#
# Deliberately NOT backed up:
#   storage/framework/{cache,sessions,views}  - regenerable, and sessions are
#                                               in the database anyway
#   storage/logs                              - operational noise, rotated daily
#   vendor/, node_modules/, public/build/     - rebuilt from the release archive
#
# @see PRD section 12.7 (weekly file backup)
# =============================================================================

set -eu

# ----------------------------------------------------------------------------
# Configuration
# ----------------------------------------------------------------------------
APP_DIR="${ATHAR_APP_DIR:-$HOME/athar-app}"
BACKUP_DIR="${ATHAR_FILE_BACKUP_DIR:-$HOME/backups/files}"
RETENTION_WEEKS=8
MIN_KEPT=2
MIN_FREE_MB=300

umask 077

log() {
    printf '%s [backup-files] %s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$*"
}

fail() {
    log "FAILED: $*"
    exit 1
}

# ----------------------------------------------------------------------------
# Pre-flight
# ----------------------------------------------------------------------------
command -v tar  >/dev/null 2>&1 || fail "tar not found in PATH"
command -v gzip >/dev/null 2>&1 || fail "gzip not found in PATH"

SOURCE="$APP_DIR/storage/app"
[ -d "$SOURCE" ] || fail "source directory not found: $SOURCE"

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

FREE_MB="$(df -Pm "$BACKUP_DIR" | awk 'NR==2 {print $4}')"
SRC_MB="$(du -sm "$SOURCE" 2>/dev/null | awk '{print $1}')"

if [ -n "$FREE_MB" ] && [ -n "$SRC_MB" ]; then
    NEEDED=$((SRC_MB + MIN_FREE_MB))
    if [ "$FREE_MB" -lt "$NEEDED" ]; then
        fail "only ${FREE_MB}MB free; source is ${SRC_MB}MB and a ${MIN_FREE_MB}MB margin is required"
    fi
fi

# ----------------------------------------------------------------------------
# Archive
# ----------------------------------------------------------------------------
STAMP="$(date -u '+%Y%m%d-%H%M%S')"
TARGET="$BACKUP_DIR/athar-files-${STAMP}.tar.gz"
TMP="$TARGET.partial"

log "archiving $SOURCE (${SRC_MB:-?}MB) -> $TARGET"

# -C so the archive holds relative paths and can be restored anywhere.
if ! tar -czf "$TMP" -C "$APP_DIR/storage" app 2>"$TMP.err"; then
    log "tar stderr: $(tr '\n' ' ' < "$TMP.err" 2>/dev/null || true)"
    rm -f "$TMP" "$TMP.err"
    fail "tar exited non-zero"
fi

if [ -s "$TMP.err" ]; then
    log "tar warnings: $(tr '\n' ' ' < "$TMP.err")"
fi
rm -f "$TMP.err"

# ----------------------------------------------------------------------------
# Verify before promoting
# ----------------------------------------------------------------------------
gzip -t "$TMP" 2>/dev/null || { rm -f "$TMP"; fail "gzip integrity check failed"; }
tar -tzf "$TMP" >/dev/null 2>&1 || { rm -f "$TMP"; fail "archive listing failed"; }

ENTRIES="$(tar -tzf "$TMP" | wc -l | tr -d ' ')"
[ "$ENTRIES" -ge 1 ] || { rm -f "$TMP"; fail "archive is empty"; }

mv "$TMP" "$TARGET"
chmod 600 "$TARGET"

if command -v sha256sum >/dev/null 2>&1; then
    ( cd "$BACKUP_DIR" && sha256sum "$(basename "$TARGET")" >> "$BACKUP_DIR/SHA256SUMS" )
    chmod 600 "$BACKUP_DIR/SHA256SUMS"
fi

log "ok: $(basename "$TARGET") ($ENTRIES entries, $(wc -c < "$TARGET" | tr -d ' ') bytes)"

# ----------------------------------------------------------------------------
# Retention - 8 weeks
# ----------------------------------------------------------------------------
RETENTION_DAYS=$((RETENTION_WEEKS * 7))
COUNT="$(find "$BACKUP_DIR" -maxdepth 1 -name 'athar-files-*.tar.gz' -type f | wc -l | tr -d ' ')"

if [ "$COUNT" -gt "$MIN_KEPT" ]; then
    find "$BACKUP_DIR" -maxdepth 1 -name 'athar-files-*.tar.gz' -type f -mtime "+$RETENTION_DAYS" -print \
    | while IFS= read -r old; do
        REMAINING="$(find "$BACKUP_DIR" -maxdepth 1 -name 'athar-files-*.tar.gz' -type f | wc -l | tr -d ' ')"
        if [ "$REMAINING" -gt "$MIN_KEPT" ]; then
            rm -f "$old"
            log "pruned $(basename "$old")"
        fi
    done
else
    log "retention skipped: only $COUNT archive(s) on disk, minimum kept is $MIN_KEPT"
fi

log "done. $(find "$BACKUP_DIR" -maxdepth 1 -name 'athar-files-*.tar.gz' -type f | wc -l | tr -d ' ') archive(s) retained."
exit 0
