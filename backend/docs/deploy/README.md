# SalonHub — production deployment runbook

Follow this top to bottom on a fresh Ubuntu 24.04 VPS. Two pieces are **not
optional**:

- **The queue worker (Step 8).** Every Mailable in this app implements
  `ShouldQueue` (`app/Mail/BookingConfirmationMail.php`,
  `NewBookingMail.php`, `BookingCancelledMail.php`,
  `BookingRescheduledMail.php`, `CustomerLoginCodeMail.php`,
  `ContactMessageMail.php`). With no worker running, zero booking
  confirmations, cancellations, reschedules, login codes or contact-form
  notifications are ever delivered — they sit in the `redis` queue forever.
- **The scheduler cron (Step 9).** `bootstrap/app.php` schedules
  `reminders:send` hourly and `bookings:release-abandoned` every 15
  minutes. Neither command runs on its own; without the cron line, no
  appointment reminder is ever sent and abandoned, unpaid held bookings
  never release their slot.

Every command below is paste-ready for a fresh server. The only values you
must supply are genuine per-server secrets — each is marked `CHANGE-ME` (or,
for shell heredocs, `REPLACE_WITH_...`) and none are real production
credentials.

---

## 1. Server prerequisites (Ubuntu 24.04)

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y software-properties-common curl git unzip
```

### PHP 8.4

`backend/composer.json` requires `"php": "^8.2"`; we install 8.4, the
current stable release satisfying that floor, via the Sury PPA (Ubuntu
24.04 ships 8.3 in universe, not 8.4):

```bash
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update
sudo apt install -y php8.4-fpm php8.4-cli php8.4-mbstring php8.4-xml \
  php8.4-curl php8.4-zip php8.4-mysql php8.4-redis
sudo systemctl enable --now php8.4-fpm
```

Verify:

```bash
php -v
```

Expected: first line starts with `PHP 8.4.`.

```bash
sudo systemctl status php8.4-fpm --no-pager
```

Expected: `Active: active (running)`.

### MySQL 8

`backend/config/database.php`'s built-in default is `sqlite`; production
selects the `mysql` connection via `DB_CONNECTION=mysql`, set in
`docs/deploy/env.production.example` (Task 9's template, copied to `.env`
in Step 5).

```bash
sudo apt install -y mysql-server
sudo systemctl enable --now mysql
```

Verify:

```bash
mysql --version
```

Expected: reports `Ver 8.0.` or newer.

### Redis

Backs the queue (`QUEUE_CONNECTION=redis`) and cache/session stores.

```bash
sudo apt install -y redis-server
sudo systemctl enable --now redis-server
```

Verify:

```bash
redis-cli ping
```

Expected: `PONG`.

### nginx

```bash
sudo apt install -y nginx
sudo systemctl enable --now nginx
```

Verify:

```bash
sudo systemctl status nginx --no-pager
```

Expected: `Active: active (running)`.

### supervisor

Runs the queue worker (Step 8).

```bash
sudo apt install -y supervisor
sudo systemctl enable --now supervisor
```

Verify:

```bash
sudo supervisorctl version
```

Expected: prints a version number, e.g. `4.2.5`, with no error.

### Node.js

`frontend/package.json` pins `"engines": { "node": "^22.18.0 || >=24.12.0" }`.

```bash
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs
```

Verify:

```bash
node -v
```

Expected: `v22.18.0` or newer in the 22.x line (or `v24.12.0`+).

### Composer

```bash
sudo apt install -y composer
```

Verify:

```bash
composer --version
```

Expected: prints `Composer version 2.` or newer.

### certbot with the Cloudflare DNS plugin

Needed for the wildcard certificate in Step 3 — issuing `*.salonhub.com`
requires the DNS-01 challenge, which the HTTP-01 challenge used by the
plain `certbot --nginx` flow cannot do.

```bash
sudo apt install -y certbot python3-certbot-dns-cloudflare
```

Verify:

```bash
certbot --version
```

Expected: prints `certbot 2.` or newer.

---

## 2. DNS

At your DNS provider (Cloudflare, per the CORS/APP_DOMAIN setup in Task 8),
create, all pointed at the VPS's public IP:

| Type | Name | Proxy |
|------|------|-------|
| A    | `app`  | Proxied |
| A    | `@`    | Proxied |
| A    | `*`    | Proxied |

Salon booking sites live at `<slug>.salonhub.com` (`APP_DOMAIN=salonhub.com`
in the production `.env` — see Step 5), so the wildcard `A *` record is
required, not optional; the app vhost alone (`app`, `@`) is not enough.

Proxying through Cloudflare is fine for the `A` records themselves — it does
not interfere with the DNS-01 challenge in the next step, which validates
via a `TXT` record, not HTTP. It does mean the Cloudflare dashboard's
SSL/TLS mode must be set to **Full (strict)**: both `nginx-app.conf` and
`nginx-salon.conf` redirect HTTP to HTTPS, so Cloudflare's default
"Flexible" mode (HTTP to the origin) produces an infinite redirect loop for
every visitor.

Verify (from any machine, once DNS has propagated):

```bash
dig +short app.salonhub.com
dig +short anything.salonhub.com
```

Expected: both print the VPS's public IP.

---

## 3. TLS certificate — wildcard, DNS-01

Salon subdomains live at `<slug>.APP_DOMAIN` (Task 8's CORS config matches
that same pattern in `backend/config/cors.php`), so the certificate must
cover the wildcard, not just the apex. One certificate, requested with both
names, covers `app.salonhub.com`, `www.salonhub.com`, the bare
`salonhub.com` apex, and every `<slug>.salonhub.com` — both vhosts
installed in Step 6 point at the same cert files.

Create the Cloudflare API token credentials file (a genuine per-server
secret — generate a Cloudflare API token scoped to `Zone:DNS:Edit` for
`salonhub.com` and substitute it below):

```bash
sudo mkdir -p /root/.secrets/certbot
sudo tee /root/.secrets/certbot/cloudflare.ini > /dev/null <<'EOF'
dns_cloudflare_api_token = REPLACE_WITH_CLOUDFLARE_API_TOKEN
EOF
sudo chmod 600 /root/.secrets/certbot/cloudflare.ini
```

Request the certificate. `--deploy-hook` reloads nginx on every renewal so
a renewed cert actually takes effect instead of nginx serving the expired
one from memory until someone notices:

```bash
sudo certbot certonly --dns-cloudflare \
  --dns-cloudflare-credentials /root/.secrets/certbot/cloudflare.ini \
  --deploy-hook "systemctl reload nginx" \
  -d salonhub.com -d '*.salonhub.com'
```

Verify:

```bash
sudo certbot certificates
```

Expected: one certificate for `salonhub.com`, `Domains:` line lists both
`salonhub.com` and `*.salonhub.com`, `VALID:` shows roughly 90 days.

Certbot's systemd timer (`certbot.timer`, installed automatically with the
package) renews it automatically — no cron entry needed for this.

---

## 4. Database

Create the database and a user matching the `DB_*` values you will put in
`.env` in Step 5. `salonhub` is the database/user name used by
`docs/deploy/env.production.example`; the password is a genuine per-server
secret — generate one and use the *same* value here and in `.env`:

```bash
openssl rand -base64 32
```

```bash
sudo mysql -u root <<'SQL'
CREATE DATABASE salonhub CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'salonhub'@'127.0.0.1' IDENTIFIED BY 'REPLACE_WITH_GENERATED_PASSWORD';
GRANT ALL PRIVILEGES ON salonhub.* TO 'salonhub'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
```

The user is granted at `127.0.0.1`, not `localhost` — `env.production.example`
sets `DB_HOST=127.0.0.1`, and MySQL treats a TCP connection to `127.0.0.1`
as a different client host than a `localhost` socket connection; granting
to the wrong one fails silently at connect time.

Verify:

```bash
mysql -u salonhub -p -h 127.0.0.1 salonhub -e "SELECT 1"
```

Expected: prompts for the password, then prints a `1` row with no error.

---

## 5. Clone the app and configure the environment

```bash
sudo mkdir -p /var/www/salonhub
sudo chown "$(whoami)":"$(whoami)" /var/www/salonhub
git clone <YOUR_GIT_REMOTE_URL> /var/www/salonhub
cd /var/www/salonhub/backend
```

Install PHP dependencies now — `php artisan` needs `vendor/autoload.php` to
run at all, so this has to happen before `key:generate` below:

```bash
composer install --no-dev --optimize-autoloader
```

Copy the production environment template (`docs/deploy/env.production.example`,
from Task 9) to `.env` and fill in every value it marks `CHANGE-ME`:

```bash
cp docs/deploy/env.production.example .env
nano .env
```

Then generate the app key and link the public storage disk:

```bash
php artisan key:generate
php artisan storage:link
```

Set ownership so php-fpm and the queue worker (both run as `www-data`, per
`salonhub-worker.conf`) can write logs, cache and sessions:

```bash
sudo chown -R www-data:www-data /var/www/salonhub/backend/storage \
  /var/www/salonhub/backend/bootstrap/cache
sudo chmod -R 775 /var/www/salonhub/backend/storage \
  /var/www/salonhub/backend/bootstrap/cache
```

Verify the app key was written:

```bash
grep -c '^APP_KEY=base64:' /var/www/salonhub/backend/.env
```

Expected: `1`.

---

## 6. Install the nginx vhosts

```bash
sudo cp /var/www/salonhub/backend/docs/deploy/nginx-app.conf \
  /etc/nginx/sites-available/salonhub-app.conf
sudo cp /var/www/salonhub/backend/docs/deploy/nginx-salon.conf \
  /etc/nginx/sites-available/salonhub-salon.conf
sudo ln -sf /etc/nginx/sites-available/salonhub-app.conf /etc/nginx/sites-enabled/
sudo ln -sf /etc/nginx/sites-available/salonhub-salon.conf /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
```

`nginx -t` must pass before reloading — never reload on a config that fails
this check:

```bash
sudo nginx -t
```

Expected: two lines ending `syntax is ok` and `test is successful`.

```bash
sudo systemctl reload nginx
```

Verify end to end (the frontend has not been built yet, so this checks
routing and TLS only — the SPA shell will show its "build is missing"
notice until Step 7 runs):

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://app.salonhub.com/up
```

Expected: `200`.

---

## 7. Install the queue worker

```bash
sudo mkdir -p /var/log/salonhub
sudo chown www-data:www-data /var/log/salonhub
sudo cp /var/www/salonhub/backend/docs/deploy/salonhub-worker.conf \
  /etc/supervisor/conf.d/salonhub-worker.conf
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start salonhub-worker:*
```

Verify:

```bash
sudo supervisorctl status salonhub-worker:*
```

Expected: two lines (`salonhub-worker:salonhub-worker_00` and `_01`), both
`RUNNING`.

---

## 8. Install the scheduler cron

`reminders:send` and `bookings:release-abandoned` only run when something
calls `schedule:run` every minute — Laravel does not run its own scheduler
process. Install the cron entry for `www-data` (the same user php-fpm and
the queue worker run as):

```bash
(sudo crontab -l -u www-data 2>/dev/null; echo '* * * * * cd /var/www/salonhub/backend && php artisan schedule:run >> /dev/null 2>&1') | sudo crontab -u www-data -
```

Without this line, `reminders:send` (hourly) never fires so no appointment
reminder is sent, and `bookings:release-abandoned` (every 15 minutes) never
fires so unpaid held slots are never released back to availability.

Verify the line is installed:

```bash
sudo crontab -l -u www-data
```

Expected: prints the `* * * * * cd /var/www/salonhub/backend && php artisan schedule:run >> /dev/null 2>&1` line.

Verify both commands are actually scheduled:

```bash
cd /var/www/salonhub/backend && php artisan schedule:list
```

Expected: two rows, `reminders:send` next-runs within the hour and
`bookings:release-abandoned` next-runs within 15 minutes.

Verify cron is actually invoking it (wait at least one minute after
installing, then check the system log for the `www-data` cron job):

```bash
sudo journalctl -u cron --since "5 minutes ago" | grep 'www-data'
```

Expected: at least one `CRON (www-data) CMD` line per minute since install.

---

## 9. First deploy

Now that nginx, the queue worker and cron are all installed, run the deploy
script — it builds the frontend, points it at the right path, restarts the
worker and reloads php-fpm, all of which now have somewhere to restart into:

```bash
cd /var/www/salonhub && ./backend/docs/deploy/deploy.sh
```

`deploy.sh` builds the frontend with `npm run build` (output directory
`frontend/dist`, per `frontend/vite.config.js`'s `build: { manifest: true }`)
and copies it to `backend/public/app` — exactly the path
`resources/views/app.blade.php` reads
(`public_path('app/.vite/manifest.json')` for the manifest,
`/app/{css,js}` for the asset URLs), and the same path `backend/.gitignore`
excludes from version control (`/public/app`) since it is a build artifact,
not source.

---

## 10. Verification checklist

Run every one of these after the first deploy. All must pass before calling
the server live.

```bash
curl -sf https://app.salonhub.com/up
```
Expected: exits `0`, empty/OK body — the health check route.

```bash
echo | openssl s_client -connect anything.salonhub.com:443 -servername anything.salonhub.com 2>/dev/null \
  | openssl x509 -noout -subject -ext subjectAltName
```
Expected: `subject=CN = salonhub.com` and a `X509v3 Subject Alternative Name`
line listing both `DNS:salonhub.com` and `DNS:*.salonhub.com` — proves the
wildcard cert (Step 3), not just the apex, is what nginx is actually
serving on a salon subdomain.

```bash
sudo supervisorctl status salonhub-worker:*
```
Expected: both processes `RUNNING`.

```bash
cd /var/www/salonhub/backend && php artisan queue:work --once
```
Expected: drains one queued job (or exits immediately with nothing to do
if the queue is empty) with no error — proves a worker process can connect
to Redis and process a job, independent of the supervisor-managed workers.

```bash
cd /var/www/salonhub/backend && php artisan schedule:list
```
Expected: `reminders:send` and `bookings:release-abandoned` both listed
with upcoming next-run times.

```bash
curl -s https://app.salonhub.com/ | grep -o '<script type="module" src="/app/[^"]*"'
```
Expected: one `<script>` tag pointing at a hashed file under `/app/assets/`
— proves the manifest was found and the SPA shell is not showing the
"build is missing" notice.

- Register a test account through the dashboard and confirm the
  verification email arrives.
- Make a test booking on a salon's public booking page and confirm the
  booking confirmation email arrives.

Both of the last two prove, end to end, that a `ShouldQueue` Mailable was
queued, picked up by the supervisor-managed worker, and delivered — not
just that the worker process is running.

---

## Redeploying

Every subsequent deploy is just:

```bash
cd /var/www/salonhub && ./backend/docs/deploy/deploy.sh
```

---

## What's not covered here

Error monitoring (Sentry) and database backups are handled separately and
are not part of this runbook.
