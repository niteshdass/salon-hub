#!/usr/bin/env bash
# Deploy SalonHub. Run from the repo root on the server.
set -euo pipefail

APP_DIR=/var/www/salonhub

cd "$APP_DIR"
git pull --ff-only

# Backend
cd "$APP_DIR/backend"
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan storage:link || true
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Frontend — built here, then copied under the Laravel public root so one
# vhost serves both and the SPA shell (resources/views/app.blade.php) can
# read the Vite manifest.
cd "$APP_DIR/frontend"
npm ci
npm run build
rm -rf "$APP_DIR/backend/public/app"
cp -r dist "$APP_DIR/backend/public/app"

# Restart the worker LAST, so it picks up the new code, and reload php-fpm
# so the opcache sees the new files.
sudo supervisorctl restart salonhub-worker:*
sudo systemctl reload php8.4-fpm

echo "Deployed. Verify: curl -sf https://app.salonhub.com/up"
