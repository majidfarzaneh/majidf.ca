#!/usr/bin/env bash
# Nightly backup (/etc/cron.d/majidf-backup): the database and the uploaded files
# (payment receipts), kept for 14 days in /var/backups/multisite/majidf/. Run as root.
set -euo pipefail

APP_DIR=/www/wwwroot/majidf.ca
DEST=/var/backups/multisite/majidf
DB=majidf_db
KEEP_DAYS=14
STAMP=$(date +%Y%m%d-%H%M%S)
STORAGE=$(docker volume inspect majidf_storage --format '{{ .Mountpoint }}')

mkdir -p "$DEST" && chmod 700 "$DEST"

# Database: verified, not trusted by exit code (SERVER-RUNBOOK.md §7).
dump="$DEST/$DB-$STAMP.sql.gz"
# The database runs in the compose stack's mysql container (127.0.0.1:3307).
docker compose -f "$APP_DIR/docker-compose.prod.yml" exec -T mysql sh -c \
  'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqldump -h 127.0.0.1 -P 3307 -uroot --single-transaction --quick --routines --triggers --no-tablespaces "$MYSQL_DATABASE"' \
  | gzip -9 > "$dump.tmp"
gzip -t "$dump.tmp"
zcat "$dump.tmp" | tail -n 1 | grep -q 'Dump completed' || { echo "dump incomplete: $dump" >&2; rm -f "$dump.tmp"; exit 1; }
mv "$dump.tmp" "$dump"

# Uploaded files (storage/app: private receipts, public files).
tar -czf "$DEST/files-$STAMP.tar.gz" -C "$STORAGE" app

find "$DEST" -type f -mtime +"$KEEP_DAYS" -delete
echo "backup ok: $(du -h "$dump" | cut -f1) database, $(du -h "$DEST/files-$STAMP.tar.gz" | cut -f1) files"
