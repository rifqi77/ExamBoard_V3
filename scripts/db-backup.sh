#!/usr/bin/env bash
#
# Nightly database backup script for the Exam Dashboard.
#
# What it does:
#   1. Reads DB credentials from .env (no creds embedded — change the .env, change the backup).
#   2. mysqldump → gzip → /var/backups/exam-board/<host>-<YYYY-MM-DD>.sql.gz
#   3. (Optional) syncs the dump off-host via rsync OR aws s3.
#   4. Prunes local copies older than LOCAL_RETENTION_DAYS (default 14).
#   5. Logs to syslog so the operator can `journalctl --grep` for failures.
#
# Designed to be run from cron once a day (typically 03:00 local), and from
# the systemd timer the README recommends. Idempotent + safe to re-run.
#
# Usage:
#   ./scripts/db-backup.sh
#
# Configuration (via environment, with sensible defaults):
#   APP_PATH                directory containing .env (default: this script's parent's parent)
#   BACKUP_DIR              local backup directory (default: /var/backups/exam-board)
#   LOCAL_RETENTION_DAYS    keep local dumps this many days (default: 14)
#
#   # Off-host: pick ONE of these, or leave both blank for local-only
#   REMOTE_RSYNC_TARGET     e.g. "backup@nas.lan:/srv/backups/exam-board/"
#   REMOTE_S3_BUCKET        e.g. "s3://my-exam-backups/exam-board/"
#                           (requires AWS CLI configured for the user running this script)
#
set -euo pipefail

# ---- Resolve paths --------------------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_PATH="${APP_PATH:-$(cd "$SCRIPT_DIR/.." && pwd)}"
ENV_FILE="$APP_PATH/.env"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/exam-board}"
LOCAL_RETENTION_DAYS="${LOCAL_RETENTION_DAYS:-14}"

# ---- Logging --------------------------------------------------------------
TAG="exam-db-backup"
log()  { logger -t "$TAG" -p user.info  "$*";  echo "[$(date -u +%FT%TZ)] $*"; }
die()  { logger -t "$TAG" -p user.err   "FAIL: $*"; echo "ERROR: $*" >&2; exit 1; }

[ -r "$ENV_FILE" ] || die ".env not readable at $ENV_FILE"

# ---- Parse .env (only the DB_* keys; ignore comments + quoting) -----------
readkv() {
    local key="$1"
    grep -E "^${key}=" "$ENV_FILE" | head -n1 | cut -d= -f2- | sed -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'$/\1/" | xargs
}
DB_CONN="$(readkv DB_CONNECTION)"
DB_HOST="$(readkv DB_HOST)"
DB_PORT="$(readkv DB_PORT)"
DB_NAME="$(readkv DB_DATABASE)"
DB_USER="$(readkv DB_USERNAME)"
DB_PASS="$(readkv DB_PASSWORD)"

if [ "$DB_CONN" != "mysql" ] && [ "$DB_CONN" != "mariadb" ]; then
    die "DB_CONNECTION is '$DB_CONN' — only mysql/mariadb are supported by this script."
fi
[ -n "$DB_NAME" ] || die "DB_DATABASE is empty in $ENV_FILE"
[ -n "$DB_USER" ] || die "DB_USERNAME is empty in $ENV_FILE"

# ---- Snapshot -------------------------------------------------------------
mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"
HOSTNAME_SAFE="$(hostname -s | tr -c 'A-Za-z0-9-' '-')"
TODAY="$(date -u +%Y-%m-%d)"
OUT="$BACKUP_DIR/${HOSTNAME_SAFE}-${DB_NAME}-${TODAY}.sql.gz"
TMP="${OUT}.tmp"

log "Snapshot starting: ${DB_NAME} → ${OUT}"

# MYSQL_PWD avoids password-on-command-line (which shows up in `ps`).
MYSQL_PWD="$DB_PASS" mysqldump \
    --host="$DB_HOST" --port="${DB_PORT:-3306}" --user="$DB_USER" \
    --single-transaction --quick --routines --triggers --events \
    --no-tablespaces --skip-lock-tables \
    --default-character-set=utf8mb4 \
    "$DB_NAME" 2>/tmp/${TAG}-err.$$ | gzip -9 > "$TMP" || {
        ERR="$(cat /tmp/${TAG}-err.$$ 2>/dev/null || true)"
        rm -f "$TMP" /tmp/${TAG}-err.$$
        die "mysqldump failed: $ERR"
    }
rm -f /tmp/${TAG}-err.$$

# Verify non-empty + a plausible SQL header so partial transfers are caught.
[ -s "$TMP" ] || { rm -f "$TMP"; die "Dump file is empty"; }
if ! zcat "$TMP" | head -c 256 | grep -qiE "mysqldump|server version|create"; then
    rm -f "$TMP"; die "Dump file is missing the expected SQL header — corrupted?"
fi

mv "$TMP" "$OUT"
SIZE="$(stat -c%s "$OUT" 2>/dev/null || stat -f%z "$OUT")"
log "Snapshot OK: $(numfmt --to=iec --suffix=B "$SIZE" 2>/dev/null || echo "${SIZE}B")"

# ---- Off-host copy (optional) ---------------------------------------------
if [ -n "${REMOTE_RSYNC_TARGET:-}" ]; then
    log "Pushing off-host via rsync to ${REMOTE_RSYNC_TARGET}"
    rsync --partial --inplace -e "ssh -o StrictHostKeyChecking=yes" "$OUT" "$REMOTE_RSYNC_TARGET" \
        || die "rsync to ${REMOTE_RSYNC_TARGET} failed"
    log "rsync OK"
fi
if [ -n "${REMOTE_S3_BUCKET:-}" ]; then
    log "Pushing off-host via aws s3 to ${REMOTE_S3_BUCKET}"
    aws s3 cp "$OUT" "$REMOTE_S3_BUCKET" --only-show-errors --storage-class STANDARD_IA \
        || die "aws s3 cp to ${REMOTE_S3_BUCKET} failed"
    log "s3 OK"
fi

# ---- Prune old local copies -----------------------------------------------
DELETED=$(find "$BACKUP_DIR" -name "${HOSTNAME_SAFE}-${DB_NAME}-*.sql.gz" -type f -mtime "+${LOCAL_RETENTION_DAYS}" -print -delete | wc -l)
log "Pruned $DELETED local copies older than ${LOCAL_RETENTION_DAYS} days"

log "Backup complete: $OUT"
