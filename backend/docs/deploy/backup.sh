#!/usr/bin/env bash
# Nightly backup: database dump + uploaded images. Reads credentials from the
# app's own .env so there is no second place to rotate a password. Runs as
# the `deploy` user via cron (see docs/deploy/README.md's backup section) —
# the destination directory is 0700 because it holds every salon's complete,
# plaintext customer list (names, phones, emails, booking history) once
# gunzipped, and the DB password never touches argv or the environment of a
# child process, because both leak through /proc on a shared box.
set -euo pipefail

APP_DIR=/var/www/salonhub/backend
DEST=/var/backups/salonhub
RETAIN_DAYS=14
STAMP=$(date +%F)

# Populated below; the trap removes whichever of these actually got created,
# on any exit path (success, error, or a stray signal) — nothing sensitive
# or half-written is ever left behind.
MYSQL_DEFAULTS_FILE=""
DB_DUMP_TMP=""
STORAGE_TMP=""
cleanup() {
  rm -f "$MYSQL_DEFAULTS_FILE" "$DB_DUMP_TMP" "$STORAGE_TMP"
}
trap cleanup EXIT

# --- Read DB_DATABASE / DB_USERNAME / DB_PASSWORD / DB_HOST from .env ------
# Laravel .env values are routinely quoted (DB_PASSWORD="p@ss word#1") and
# may contain '=', '#' or whitespace. `export $(grep ... | xargs)` mangles
# all three: xargs word-splits on whitespace, strips quotes without
# respecting them as delimiters, and treats '#' as a comment start wherever
# it appears. With set -u that can silently leave DB_PASSWORD unset and the
# script dies (or worse, dumps with the wrong credentials) mid-backup.
#
# Read line by line instead: IFS= read -r means no word-splitting, take
# everything after the *first* '=' verbatim (so '=' inside a value is kept),
# then strip at most one matching layer of surrounding quotes. No comment
# stripping is performed anywhere, so a '#' inside a quoted value survives.
declare -A db_env
while IFS= read -r line; do
  key=${line%%=*}
  value=${line#*=}
  if [[ $value == \"*\" && $value == *\" ]]; then
    value=${value#\"}
    value=${value%\"}
  elif [[ $value == \'*\' && $value == *\' ]]; then
    value=${value#\'}
    value=${value%\'}
  fi
  db_env[$key]=$value
done < <(grep -E '^DB_(DATABASE|USERNAME|PASSWORD|HOST)=' "$APP_DIR/.env")

DB_DATABASE=${db_env[DB_DATABASE]:?DB_DATABASE missing from $APP_DIR/.env}
DB_USERNAME=${db_env[DB_USERNAME]:?DB_USERNAME missing from $APP_DIR/.env}
DB_PASSWORD=${db_env[DB_PASSWORD]:?DB_PASSWORD missing from $APP_DIR/.env}
DB_HOST=${db_env[DB_HOST]:-127.0.0.1}

# 0700: this directory holds every salon's plaintext customer data once a
# dump is gunzipped. Enforced on every run, not just at creation, in case
# something ever loosens it. (The parent /var/backups itself must already
# exist and be owned by the backup user — see the README setup steps; a
# non-root `deploy` cannot create it.)
mkdir -p "$DEST"
chmod 700 "$DEST"

# --- Database dump -----------------------------------------------------------
# The password goes in a 0600 MySQL "option file" instead of on the command
# line, so it never appears in `ps aux` output or a child process's argv —
# both readable by any user on the box for the whole life of the dump.
# MYSQL_PWD was considered and rejected: it avoids argv but is still
# readable via /proc/<pid>/environ on some kernels/configurations.
# --defaults-extra-file must be the *first* mysqldump argument. Values are
# double-quoted in the option file itself because MySQL's option-file syntax
# also treats an unquoted '#' as a comment start.
MYSQL_DEFAULTS_FILE=$(mktemp)
chmod 600 "$MYSQL_DEFAULTS_FILE"
cat > "$MYSQL_DEFAULTS_FILE" <<EOF
[client]
user="$DB_USERNAME"
password="$DB_PASSWORD"
host="$DB_HOST"
EOF

# --single-transaction keeps InnoDB consistent without locking the salon out
# mid-booking. Dump to a hidden .tmp name and rename on success only: with
# set -o pipefail a failed mysqldump fails the pipeline, but gzip will still
# have written a partial .gz to disk by that point. Without the tmp+rename
# step that partial file would sit at the real backup name and a later
# restore (or a human skimming `ls`) would have no way to tell it apart from
# a real, complete backup.
DB_DUMP_TMP="$DEST/.salonhub-$STAMP.sql.gz.tmp"
mysqldump --defaults-extra-file="$MYSQL_DEFAULTS_FILE" \
  --single-transaction --quick --no-tablespaces "$DB_DATABASE" \
  | gzip > "$DB_DUMP_TMP"
mv "$DB_DUMP_TMP" "$DEST/salonhub-$STAMP.sql.gz"
DB_DUMP_TMP=""

# --- Uploaded images -----------------------------------------------------
# Logos, covers and gallery images live on the local public disk, not in the
# database. Same tmp-then-rename discipline as the dump above, for the same
# reason: a `tar` that fails partway must not leave a file at the real name.
STORAGE_TMP="$DEST/.storage-$STAMP.tar.gz.tmp"
tar -czf "$STORAGE_TMP" -C "$APP_DIR/storage/app" public
mv "$STORAGE_TMP" "$DEST/storage-$STAMP.tar.gz"
STORAGE_TMP=""

# A backup that only exists on the machine it protects is not a backup.
# Uncomment once an off-site target is configured:
# rclone copy "$DEST" remote:salonhub-backups --max-age 25h

# -maxdepth 1: $DEST is a flat directory by construction (this script never
# creates subdirectories in it) — pinning the depth means a future change
# that does create one can't have its contents swept up by a name match.
find "$DEST" -maxdepth 1 -name '*.gz' -mtime "+$RETAIN_DAYS" -delete

echo "Backup complete: $DEST/salonhub-$STAMP.sql.gz"
