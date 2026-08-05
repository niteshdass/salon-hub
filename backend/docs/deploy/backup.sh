#!/usr/bin/env bash
# Nightly backup: database dump + uploaded images. Reads credentials from the
# app's own .env so there is no second place to rotate a password. Runs as
# the `deploy` user via cron (see docs/deploy/README.md's backup section) —
# the destination directory is 0700 because it holds every salon's complete,
# plaintext customer list (names, phones, emails, booking history) once
# gunzipped, and the DB password never touches argv or the environment of a
# child process, because both leak through /proc on a shared box.
set -euo pipefail
umask 077

APP_DIR=/var/www/salonhub/backend
DEST=/var/backups/salonhub
RETAIN_DAYS=14
STAMP=$(date +%F)

# Populated below; the trap removes whichever of these actually got created,
# on any exit path — normal return, a `set -e` abort, or a caught signal —
# so nothing sensitive or half-written is ever left behind. bash runs the
# EXIT trap on SIGTERM/SIGINT/SIGHUP too, as long as nothing else traps them
# (nothing here does). SIGKILL cannot be trapped by any process, by design
# of the signal — a SIGKILL mid-run can leave the 0600 mysql option file
# sitting in $TMPDIR (not in $DEST, and not the customer-data files
# themselves), cleared by the next reboot or systemd-tmpfiles run on a
# standard Ubuntu install.
MYSQL_DEFAULTS_FILE=""
DB_DUMP_TMP=""
STORAGE_TMP=""
cleanup() {
  rm -f "$MYSQL_DEFAULTS_FILE" "$DB_DUMP_TMP" "$STORAGE_TMP"
}
trap cleanup EXIT

# === FUNCTIONS START ===============================================

# --- Parse a single dotenv value, matching vlucas/phpdotenv's semantics ----
# (vendored at backend/vendor/vlucas/phpdotenv/src/Parser/EntryParser.php —
# the same library `php artisan config:cache` uses to load this same file —
# read directly rather than guessed at). Handles exactly what that parser
# handles for a single-line value: unquoted (cut at the first '#', no
# escaping — phpdotenv has no way to escape '#' outside quotes), single-quoted
# (fully literal, no escapes at all, closed by the next '), and double-quoted
# (\" \\ \$ \n \r \t \f \v are recognized escapes; anything after the closing
# quote — including a trailing "# comment", exactly as env.production.example
# ships — is discarded, matching phpdotenv's WHITESPACE_STATE/COMMENT_STATE).
# Does NOT implement phpdotenv's multiline double-quoted values or ${VAR}
# interpolation: env.production.example's DB_* lines never use either, and
# this script only ever reads DB_DATABASE/DB_USERNAME/DB_PASSWORD/DB_HOST/DB_PORT.
parse_dotenv_value() {
  local raw=$1 first rest v out i c esc len

  # phpdotenv splits the whole file on \r\n|\n|\r before parsing a single
  # line, so a CRLF-saved .env is read exactly the same as an LF one — strip
  # a trailing CR here for the same effect.
  raw=${raw%$'\r'}
  # Trim leading/trailing whitespace, matching phpdotenv's
  # trim($value, " \n\r\t\0\x0B").
  raw="${raw#"${raw%%[![:space:]]*}"}"
  raw="${raw%"${raw##*[![:space:]]}"}"

  if [[ -z $raw ]]; then
    return 0
  fi

  first=${raw:0:1}

  if [[ $first == "'" ]]; then
    rest=${raw:1}
    printf '%s' "${rest%%\'*}"
    return 0
  fi

  if [[ $first == '"' ]]; then
    rest=${raw:1}
    len=${#rest}
    out=''
    i=0
    while (( i < len )); do
      c=${rest:i:1}
      if [[ $c == '"' ]]; then
        printf '%s' "$out"
        return 0
      elif [[ $c == $'\\' ]]; then
        esc=${rest:i+1:1}
        case $esc in
          '"') out+='"' ;;
          $'\\') out+=$'\\' ;;
          '$') out+='$' ;;
          n) out+=$'\n' ;;
          r) out+=$'\r' ;;
          t) out+=$'\t' ;;
          f) out+=$'\f' ;;
          v) out+=$'\v' ;;
          *) out+=$c$esc ;;
        esac
        i=$((i + 2))
      else
        out+=$c
        i=$((i + 1))
      fi
    done
    # No closing quote found: phpdotenv would treat this as a multiline
    # value continuation or, failing that, refuse to boot the app at all.
    # Rather than silently guess, return what was gathered so far.
    printf '%s' "$out"
    return 0
  fi

  # Unquoted: an unescaped '#' always starts a comment in phpdotenv — there
  # is no escape for it outside quotes — so cut there, then trim the
  # whitespace that sat between the value and the '#'.
  v=${raw%%#*}
  printf '%s' "${v%"${v##*[![:space:]]}"}"
}

# --- Escape a value for MySQL's option-file ("my.cnf") format --------------
# Separate from the dotenv escaping above: this is MySQL's own quoting
# format for --defaults-extra-file, which recognizes '\' as an escape
# introducer and '"' as the delimiter inside a double-quoted value. Both
# must be escaped or a password containing either breaks the *dump* silently
# (wrong credentials passed to MySQL) while still being perfectly valid to
# Laravel, which decodes it correctly via phpdotenv.
mysql_opt_escape() {
  local v=$1
  v=${v//\\/\\\\}
  v=${v//\"/\\\"}
  printf '%s' "$v"
}

# === FUNCTIONS END ===================================================

# --- Read DB_DATABASE / DB_USERNAME / DB_PASSWORD / DB_HOST / DB_PORT ------
declare -A db_env
while IFS= read -r line; do
  line=${line%$'\r'}
  key=${line%%=*}
  db_env[$key]=$(parse_dotenv_value "${line#*=}")
done < <(grep -E '^DB_(DATABASE|USERNAME|PASSWORD|HOST|PORT)=' "$APP_DIR/.env")

for required in DB_DATABASE DB_USERNAME DB_PASSWORD; do
  if [[ -z ${db_env[$required]+x} ]]; then
    echo "$required missing from $APP_DIR/.env" >&2
    exit 1
  fi
  if [[ -z ${db_env[$required]} ]]; then
    echo "$required is present in $APP_DIR/.env but empty — refusing to back up with a blank credential" >&2
    exit 1
  fi
done

DB_DATABASE=${db_env[DB_DATABASE]}
DB_USERNAME=${db_env[DB_USERNAME]}
DB_PASSWORD=${db_env[DB_PASSWORD]}
DB_HOST=${db_env[DB_HOST]:-127.0.0.1}
DB_PORT=${db_env[DB_PORT]:-3306}

if ! [[ $DB_PORT =~ ^[0-9]+$ ]]; then
  echo "DB_PORT=[$DB_PORT] in $APP_DIR/.env is not numeric" >&2
  exit 1
fi

# 0700: this directory holds every salon's plaintext customer data once a
# dump is gunzipped. Enforced on every run, not just at creation, in case
# something ever loosens it. (The parent /var/backups itself must already
# exist and be owned by the backup user — see the README setup steps; a
# non-root `deploy` cannot create it.)
mkdir -p "$DEST"
chmod 700 "$DEST"

# --- Database dump -----------------------------------------------------------
# The password goes in a 0600 MySQL option file instead of on the command
# line, so it never appears in `ps aux` output or a child process's argv —
# both readable by any user on the box for the whole life of the dump.
# MYSQL_PWD was considered and rejected: it avoids argv but is still
# readable via /proc/<pid>/environ on some kernels/configurations.
# --defaults-extra-file must be the *first* mysqldump argument.
MYSQL_DEFAULTS_FILE=$(mktemp)
chmod 600 "$MYSQL_DEFAULTS_FILE"
cat > "$MYSQL_DEFAULTS_FILE" <<EOF
[client]
user="$(mysql_opt_escape "$DB_USERNAME")"
password="$(mysql_opt_escape "$DB_PASSWORD")"
host="$(mysql_opt_escape "$DB_HOST")"
port=$DB_PORT
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
#
# If the dump above succeeded but this step fails, tonight's run ends with a
# database backup and no upload archive for the same date — deliberately not
# rolled back. A DB-only backup is still useful (it is the higher-value
# artifact and what the restore drill checks first); discarding a good
# database backup because the unrelated uploads step failed would make
# things worse, not safer. The gap is not silent: it shows up immediately in
# backup.log and as a missing storage-$STAMP.tar.gz file.
STORAGE_TMP="$DEST/.storage-$STAMP.tar.gz.tmp"
tar -czf "$STORAGE_TMP" -C "$APP_DIR/storage/app" public
mv "$STORAGE_TMP" "$DEST/storage-$STAMP.tar.gz"
STORAGE_TMP=""

# A backup that only exists on the machine it protects is not a backup.
# Uncomment once an off-site target is configured:
# rclone copy "$DEST" remote:salonhub-backups --max-age 25h

# -mtime +N matches files older than N days, which keeps N+1 distinct days
# (today plus the previous N) — RETAIN_DAYS-1 is the threshold that actually
# retains RETAIN_DAYS days. -maxdepth 1: $DEST is a flat directory by
# construction (this script never creates subdirectories in it) — pinning
# the depth means a future change that does create one can't have its
# contents swept up by a name match.
find "$DEST" -maxdepth 1 -name '*.gz' -mtime "+$((RETAIN_DAYS - 1))" -delete

# A marker an operator (or a monitoring check) can point at to answer "did
# last night's backup actually happen?" without parsing the log — see
# docs/deploy/README.md's backup section for what to alert on.
touch "$DEST/.last-success"

echo "Backup complete: $DEST/salonhub-$STAMP.sql.gz"
