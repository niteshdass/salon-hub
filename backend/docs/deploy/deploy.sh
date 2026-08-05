#!/usr/bin/env bash
# Deploy SalonHub. Run from the repo root on the server, as the `deploy`
# user (see docs/deploy/README.md's ownership model) — not root, not any
# other account — so every file this script writes stays owned deploy:www-data,
# matching storage/ and bootstrap/cache/'s permissions.
set -euo pipefail

APP_DIR=/var/www/salonhub

cd "$APP_DIR"
git pull --ff-only

# ---------------------------------------------------------------------------
# 1. Frontend build — FIRST, because it is the step most likely to fail and it
#    touches nothing live until the swap in step 4.
#
#    It used to run after `migrate --force`. Under `set -euo pipefail` an
#    `npm ci` blip or an OOM during the Vite + Tailwind build — the most
#    memory-hungry step, on a VPS the runbook sizes at 1 GB — aborted AFTER the
#    schema had changed, with php-fpm not reloaded and supervisor workers still
#    running pre-pull code from memory against the new schema. There is no
#    `php artisan down` and no rollback path, so that window had to go.
# ---------------------------------------------------------------------------

# VITE_APP_DOMAIN is load-bearing and baked in at build time: frontend/
# src/lib/tenantHost.js reads it to decide whether the current Host names a
# salon. Get it wrong and resolveSlugFromHost() returns null for every real
# salon host with NO error anywhere — every salon's booking site silently
# serves the SalonHub marketing page instead. Read it from the one place that
# already holds the truth (backend/.env, which config('app.domain') and
# config/cors.php also read) and refuse to build without it, rather than let
# the two independent hardcoded defaults quietly agree until they don't.
APP_DOMAIN="$(sed -n 's/^APP_DOMAIN=[[:space:]]*//p' "$APP_DIR/backend/.env" | head -n1 | tr -d '"'\'' ')"
if [ -z "$APP_DOMAIN" ]; then
  echo "APP_DOMAIN is not set in $APP_DIR/backend/.env — refusing to build the" >&2
  echo "frontend, because a wrong value silently serves the marketing page on" >&2
  echo "every salon's booking site. See frontend/.env.example." >&2
  exit 1
fi
echo "Building frontend for APP_DOMAIN=$APP_DOMAIN"

# --base=/app/ is required: without it, Vite emits root-relative
# url(/assets/...) references inside the built CSS (webfonts) and index.html
# (favicon) that 404 once served from /app/ instead of /, silently breaking
# brand typography. The manifest's own JSON paths stay relative regardless of
# --base, so app.blade.php's manual "/app/" prefix on the script/stylesheet
# tags is unaffected.
cd "$APP_DIR/frontend"
npm ci
VITE_APP_DOMAIN="$APP_DOMAIN" npx vite build --base=/app/

# ---------------------------------------------------------------------------
# 2. Backend dependencies and schema.
# ---------------------------------------------------------------------------
cd "$APP_DIR/backend"
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan storage:link || true
php artisan config:cache
php artisan route:cache
php artisan view:cache

# ---------------------------------------------------------------------------
# 3. Swap the built frontend under the Laravel public root, so one vhost serves
#    both and the SPA shell (resources/views/app.blade.php) can read the Vite
#    manifest.
#
#    Staged into public/app.new and moved into place, rather than
#    `rm -rf public/app && cp -r dist public/app`. Copying takes long enough
#    that live visitors hit a document root with no manifest and get the red
#    "The application build is missing" block; two renames close that to
#    effectively nothing.
# ---------------------------------------------------------------------------
PUBLIC_APP="$APP_DIR/backend/public/app"
rm -rf "$PUBLIC_APP.new" "$PUBLIC_APP.old"
cp -r "$APP_DIR/frontend/dist" "$PUBLIC_APP.new"
if [ -d "$PUBLIC_APP" ]; then
  mv "$PUBLIC_APP" "$PUBLIC_APP.old"
fi
mv "$PUBLIC_APP.new" "$PUBLIC_APP"
rm -rf "$PUBLIC_APP.old"

# ---------------------------------------------------------------------------
# 4. Restart the worker LAST, so it picks up the new code, and reload php-fpm
#    so the opcache sees the new files.
# ---------------------------------------------------------------------------
sudo supervisorctl restart salonhub-worker:*
sudo systemctl reload php8.4-fpm

echo "Deployed. Verify: curl -sf https://app.salonhub.com/up/db"
