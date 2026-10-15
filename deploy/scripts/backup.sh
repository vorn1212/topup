#!/usr/bin/env bash
# Database + uploaded pictures backup. Runs from cron every 6 hours (deploy/cron/topup.cron).
# Needs /etc/topup/backup.cnf (see deploy/DEPLOY-GUIDE.md): a file that only the deploy user can read.
# These files contain customer and money data: keep the folder private, and copy it OFF this server too.
set -Eeuo pipefail

APP_DIR="${APP_DIR:-/var/www/topup}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/topup}"
KEEP_DAYS="${KEEP_DAYS:-14}"
DEFAULTS_FILE="${DEFAULTS_FILE:-/etc/topup/backup.cnf}"

env_value() {
    grep -E "^$1=" "$APP_DIR/.env" | tail -n1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'\$//"
}

DB_NAME="$(env_value DB_DATABASE)"

if ! [[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ ]]; then
    echo "DB_DATABASE in .env is missing or has unexpected characters. Stop."
    exit 1
fi

if [ ! -r "$DEFAULTS_FILE" ]; then
    echo "Cannot read $DEFAULTS_FILE. Stop."
    exit 1
fi

umask 077
mkdir -p "$BACKUP_DIR"

STAMP="$(date +%F_%H%M%S)"
DB_FILE="$BACKUP_DIR/db_$STAMP.sql.gz"
FILES_FILE="$BACKUP_DIR/files_$STAMP.tar.gz"

mysqldump --defaults-extra-file="$DEFAULTS_FILE" \
    --single-transaction --quick --routines --triggers --no-tablespaces \
    "$DB_NAME" | gzip -9 > "$DB_FILE"

# A backup that cannot be read back is worth nothing: test it now.
gzip -t "$DB_FILE"

if [ ! -s "$DB_FILE" ]; then
    echo "The database backup is empty. Stop."
    exit 1
fi

tar -czf "$FILES_FILE" -C "$APP_DIR" storage/app/public
gzip -t "$FILES_FILE"

find "$BACKUP_DIR" -type f \( -name 'db_*.sql.gz' -o -name 'files_*.tar.gz' \) -mtime +"$KEEP_DAYS" -delete

echo "$(date -Is) backup ok: $(basename "$DB_FILE") ($(du -h "$DB_FILE" | cut -f1)), $(basename "$FILES_FILE") ($(du -h "$FILES_FILE" | cut -f1))"
