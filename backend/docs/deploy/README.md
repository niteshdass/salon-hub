# SalonHub — production deployment runbook

Follow this top to bottom on a fresh Ubuntu 24.04 VPS. Two pieces are **not
optional**:

- **The queue worker (Step 8).** Four of the app's six Mailables implement
  `ShouldQueue`: `app/Mail/BookingConfirmationMail.php`,
  `NewBookingMail.php`, `BookingCancelledMail.php` and
  `BookingRescheduledMail.php`. With no worker running, zero booking
  confirmations, cancellations or reschedules are ever delivered — they sit
  in the `redis` queue forever. The other two are sent inline, not queued,
  by design: `CustomerLoginCodeMail` (the customer is waiting on the code,
  per its own docblock) and `ContactMessageMail` (uses the `Queueable`
  trait but does not `implement ShouldQueue`). The worker is still not
  optional — it's required for the other four.
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
in the production `.env` — see Step 5), and that subdomain is where the site
is actually served from: the Host header, not the URL path, tells the
application which salon's data to return. So the wildcard `A *` record is
required, not optional; the app vhost alone (`app`, `@`) is not enough, and
without it every salon's booking site is unreachable.

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

## 5. Create the deploy user and clone the app

**Ownership model — stated once here, used consistently for the rest of
this runbook:** a dedicated `deploy` user owns the checkout and runs every
deploy, including this first one (`git`, `composer`, `npm`, `artisan` — see
`deploy.sh`, which must always be run as `deploy`). `deploy` is a member of
the `www-data` group — the same group php-fpm and the queue worker run
under, per `salonhub-worker.conf`'s `user=www-data`. `storage/` and
`bootstrap/cache/` are group-owned `www-data` with the setgid bit set, so
whichever of the two users writes a file there, the other can still read
and write it afterwards. `deploy` has no interactive login of its own —
you `sudo -iu deploy` into it whenever a step needs it.

Create the user and a narrow, passwordless sudo grant for the exact two
commands `deploy.sh` runs as root (nothing broader):

```bash
sudo useradd -m -s /bin/bash deploy
sudo usermod -aG www-data deploy
sudo tee /etc/sudoers.d/salonhub-deploy > /dev/null <<'EOF'
deploy ALL=(root) NOPASSWD: /usr/bin/supervisorctl restart salonhub-worker:*, /usr/bin/systemctl reload php8.4-fpm
EOF
sudo chmod 440 /etc/sudoers.d/salonhub-deploy
```

Verify the sudoers file is valid before relying on it — a broken sudoers
file can lock you out of `sudo` entirely, so never skip this check:

```bash
sudo visudo -c
```

Expected: every file it lists, including
`/etc/sudoers.d/salonhub-deploy: parsed OK`, with no errors.

Verify the grant actually works with no password prompt (php-fpm is
already running from Step 1, and reloading it is a no-downtime operation,
so this is safe to run for real):

```bash
sudo -u deploy sudo -n systemctl reload php8.4-fpm && echo "deploy can reload php-fpm passwordlessly"
```

Expected: `deploy can reload php-fpm passwordlessly`.

Create `/var/www/salonhub` owned by `deploy`, then switch into that user
for the clone and initial setup:

```bash
sudo mkdir -p /var/www/salonhub
sudo chown deploy:deploy /var/www/salonhub
sudo -iu deploy
```

Everything below, up to the next `exit`, runs inside that `deploy` shell.
Install PHP dependencies before `key:generate` — `php artisan` needs
`vendor/autoload.php` to run at all:

```bash
git clone <YOUR_GIT_REMOTE_URL> /var/www/salonhub
cd /var/www/salonhub/backend
composer install --no-dev --optimize-autoloader
```

Copy the production environment template (`docs/deploy/env.production.example`,
from Task 9) to `.env`, lock it to owner-only (php-fpm and the worker never
read `.env` directly in production — `deploy.sh` runs `config:cache` on
every deploy, and once a config cache exists Laravel loads config from
`bootstrap/cache/config.php` instead of re-reading `.env`), then fill in
every value it marks `CHANGE-ME`:

```bash
cp docs/deploy/env.production.example .env
chmod 600 .env
nano .env
```

Generate the app key and link the public storage disk:

```bash
php artisan key:generate
php artisan storage:link
```

**Record `APP_KEY` somewhere outside this server right now, before you
forget.** `docs/deploy/backup.sh` (Section 11) deliberately never includes
`.env` in the nightly backup archives — bundling the app's encryption key
into the same tarball as the customer data it protects would defeat the
point of encrypting `PaymentSetting.credentials` and
`ReminderSetting.credentials` (both `encrypted:array` casts) in the first
place. That means the database backup alone is not enough to recover those
two tables: a restore onto a different server with a different `APP_KEY`
decrypts them into garbage, silently, with no error at restore time. Copy
the value out now, e.g.:

```bash
grep '^APP_KEY=' .env
```

...into your team's password manager or secrets vault, labelled
`salonhub-production-app-key`. Treat it exactly like the DB password: never
in Slack, never in a plaintext note, never committed.

```bash
exit
```

`exit` returns you to your own admin/sudo-capable account — the rest of
this step needs `sudo`, which `deploy` deliberately doesn't have beyond the
one narrow grant above.

Give `www-data` group access to `storage/` and `bootstrap/cache/`, with the
setgid bit so that stays true for every file created afterwards by either
user:

```bash
sudo chgrp -R www-data /var/www/salonhub/backend/storage \
  /var/www/salonhub/backend/bootstrap/cache
sudo chmod -R 2775 /var/www/salonhub/backend/storage \
  /var/www/salonhub/backend/bootstrap/cache
```

Verify both users this actually matters for — `deploy` (runs `artisan
config:cache`/`view:cache` on every deploy) and `www-data` (php-fpm and the
queue worker, writing logs/sessions/cache at runtime) — can really write
both directories. Do this now, before Step 9's first deploy, not after:

```bash
sudo -u deploy test -w /var/www/salonhub/backend/storage && \
  sudo -u deploy test -w /var/www/salonhub/backend/bootstrap/cache && \
  echo "deploy can write both directories"
sudo -u www-data test -w /var/www/salonhub/backend/storage && \
  sudo -u www-data test -w /var/www/salonhub/backend/bootstrap/cache && \
  echo "www-data can write both directories"
```

Expected: both `deploy can write both directories` and `www-data can write
both directories` print.

Verify the app key was written (`.env` is `chmod 600` owned by `deploy`,
so read it via `sudo`):

```bash
sudo grep -c '^APP_KEY=base64:' /var/www/salonhub/backend/.env
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

Now that the program exists, verify the other half of Step 5's narrow sudo
grant — `deploy` restarting the worker with no password prompt, which
`deploy.sh` (Step 9) depends on:

```bash
sudo -u deploy sudo -n supervisorctl restart salonhub-worker:* && \
  echo "deploy can restart the worker passwordlessly"
```

Expected: shows both processes stopping and starting, then `deploy can
restart the worker passwordlessly`.

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

### Backup cron (`deploy` user)

`docs/deploy/backup.sh` (detailed in "11. Database and upload backups"
below) reads the `DB_*` values straight out of `.env`, which Step 5 locks to
`chmod 600` owned by `deploy`, so it runs under `deploy`'s crontab, not
`www-data`'s — the one user that can already open that file.

Before installing the cron line, create the backup destination and give
`deploy` write access to it and to the log directory. `/var/backups` is
root-owned and `/var/log/salonhub` is `www-data:www-data` by default
(created in Step 7); neither grants `deploy` a path in without this
one-time setup, so the cron entry below has nowhere to write until it's
done:

```bash
sudo mkdir -p /var/backups/salonhub
sudo chown deploy:deploy /var/backups/salonhub
sudo chmod 700 /var/backups/salonhub
sudo chmod g+w /var/log/salonhub
```

`/var/backups/salonhub` is `chmod 700`, owned `deploy` alone — not
`www-data`, not world-readable — because a gunzipped dump in it is every
salon's complete customer list in plaintext: names, phone numbers, emails,
booking history. `backup.sh` re-asserts this mode on every run in case it
is ever loosened. `chmod g+w` on `/var/log/salonhub` gives it group write,
and `deploy` is already a member of the `www-data` group (Step 5), so this
is the smallest change that lets `deploy` append to `backup.log` without
changing who owns the worker's own log file next to it.

Verify both:

```bash
sudo -u deploy test -w /var/backups/salonhub && \
  sudo -u deploy test -w /var/log/salonhub && \
  echo "deploy can write both backup paths"
```

Expected: `deploy can write both backup paths`.

Install the cron line:

```bash
(sudo crontab -l -u deploy 2>/dev/null; echo '15 3 * * * /var/www/salonhub/backend/docs/deploy/backup.sh >> /var/log/salonhub/backup.log 2>&1') | sudo crontab -u deploy -
```

Verify the line is installed:

```bash
sudo crontab -l -u deploy
```

Expected: prints the `15 3 * * * ...backup.sh >> /var/log/salonhub/backup.log 2>&1` line.

Don't wait until 03:15 to find out whether it actually works. Run it once
now, as `deploy`:

```bash
sudo -u deploy /var/www/salonhub/backend/docs/deploy/backup.sh
```

Expected: `Backup complete: /var/backups/salonhub/salonhub-<today>.sql.gz`
with no other output. Confirm both files landed and the success marker
(Section 11) was written:

```bash
sudo -u deploy ls -la /var/backups/salonhub
```

Expected: `salonhub-<today>.sql.gz`, `storage-<today>.tar.gz`, and
`.last-success` all present. If this step fails, fix it now — a cron job
that's never been proven to run once is not a backup you can trust to run
nightly unattended.

---

## 9. First deploy

Now that nginx, the queue worker and cron are all installed, run the deploy
script — it builds the frontend, points it at the right path, restarts the
worker and reloads php-fpm, all of which now have somewhere to restart into.
Run it as `deploy` (per Step 5's ownership model — everything `deploy.sh`
writes must stay owned `deploy`, group `www-data`, matching `storage/` and
`bootstrap/cache/`):

```bash
sudo -iu deploy
cd /var/www/salonhub && ./backend/docs/deploy/deploy.sh
exit
```

`deploy.sh` builds the frontend with `npx vite build --base=/app/` (output
directory `frontend/dist`, per `frontend/vite.config.js`'s
`build: { manifest: true }`) and copies it to `backend/public/app` —
exactly the path `resources/views/app.blade.php` reads
(`public_path('app/.vite/manifest.json')` for the manifest,
`/app/{css,js}` for the asset URLs), and the same path `backend/.gitignore`
excludes from version control (`/public/app`) since it is a build artifact,
not source.

`--base=/app/` is required, not cosmetic: a plain `vite build` (what
`npm run build` runs) emits root-relative `url(/assets/...)` references
inside the built CSS for the self-hosted `@fontsource-variable` webfonts,
and a root-relative `/favicon.ico` in `index.html`. Once that CSS is served
from `/app/assets/...` instead of `/assets/...`, those requests hit the
`/{any}` SPA-fallback route instead of the real file — a 200 of HTML, not
the font — and every page silently loses its brand typography. Confirmed
by building both ways and inspecting the output:

```
# npm run build (no --base):
dist/assets/index-*.css: url(/assets/fraunces-latin-wght-normal-*.woff2)

# npx vite build --base=/app/:
dist/assets/index-*.css: url(/app/assets/fraunces-latin-wght-normal-*.woff2)
```

`--base` only rewrites the *runtime* asset references Vite emits inside the
built JS/CSS/HTML — it does not touch `dist/.vite/manifest.json`'s own path
keys, which stay relative (`"file": "assets/index-*.js"`,
`"css": ["assets/index-*.css"]`) regardless of `--base`. `app.blade.php`'s
manual `/app/` prefix on those manifest paths is therefore still correct
and needed no change. No other root-relative asset reference exists in
`frontend/src` — the only two are the fontsource webfonts and the
`public/favicon.ico` Vite public-dir passthrough, both fixed by the same
flag.

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

The certificate check above proves TLS only. These next three prove the
thing that actually matters on a salon subdomain: that the Host header
selects the right tenant, and only the right tenant. Substitute a real
registered salon's slug for `<slug>`.

```bash
curl -s https://<slug>.salonhub.com/api/public-site/site | head -c 200
```
Expected: JSON whose `"slug"` is `<slug>` — the API resolved the tenant from
the Host header alone, with no `{org}` segment in the path.

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://no-such-salon.salonhub.com/api/public-site/site
```
Expected: `404`. An unregistered subdomain must be a 404, never a fallback
to some other salon. The same `404` is expected for a salon whose
organization has been suspended.

```bash
curl -s -o /dev/null -w '%{http_code}\n' \
  -H 'X-Forwarded-Host: <slug>.salonhub.com' \
  https://salonhub.com/api/public-site/site
```
Expected: `404`. The application trusts no proxy, so `X-Forwarded-Host` is
ignored and cannot be used to pick a tenant. If this ever returns `200`,
someone has configured trusted proxies — stop and undo it, because tenant
selection has just become a client-supplied header.

Note on the first deploy after the subdomain feature: `deploy.sh` runs
`php artisan migrate --force`, which includes the backfill that marks
already-registered `<slug>.APP_DOMAIN` rows `is_verified`. Host resolution
only answers for verified rows, so a database restored from before that
migration will 404 on every salon subdomain until it has run.

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

```bash
CSS_PATH=$(curl -s https://app.salonhub.com/ | grep -oE '/app/assets/index-[A-Za-z0-9_-]*\.css' | head -1)
curl -s "https://app.salonhub.com$CSS_PATH" | grep -c 'url(/app/assets/'
```
Expected: a number greater than `0` — proves the self-hosted webfonts'
`url(...)` references were built with `--base=/app/` and point at
`/app/assets/...`, not bare `/assets/...` (which would 404 into the SPA
fallback and silently drop the brand typography).

- Register a test account through the dashboard and confirm the
  verification email arrives.
- Make a test booking on a salon's public booking page and confirm the
  booking confirmation email arrives.

Both of the last two prove, end to end, that a `ShouldQueue` Mailable was
queued, picked up by the supervisor-managed worker, and delivered — not
just that the worker process is running.

```bash
sudo find /var/backups/salonhub/.last-success -mtime -1
```
Expected: prints the path — proves the manual run in Step 8's backup cron
subsection actually succeeded and left tonight's marker behind. `sudo` is
required here, same as every other command that reaches into
`/var/backups/salonhub`: it's `chmod 700` owned by `deploy`, so without
`sudo` this always prints nothing and exits non-zero — even on a perfectly
healthy backup — which is the opposite of what an empty result is meant to
mean. See "11. Database and upload backups" for what to check if this is
still empty after `sudo`, on a live server.

- Confirm `APP_KEY` from Step 5 was actually recorded outside this server
  (password manager / secrets vault), not just generated. Nothing on the
  server itself can verify that for you — it's a manual attestation, not a
  command — but skipping it is exactly how `PaymentSetting`/
  `ReminderSetting`'s encrypted columns become unrecoverable after a future
  restore onto different hardware. Treat it as a hard gate before calling
  the server live, same as everything else on this list.

---

## 11. Database and upload backups

A single VPS with no backup is one `DROP` or one failed disk from losing
every salon's customer list. Uploads (logos, covers, gallery images) live on
the local public disk (`backend/storage/app/public`), so a database dump
alone does not cover them — `docs/deploy/backup.sh` takes both, nightly.

**Who runs it, and why:** `backup.sh` reads DB credentials straight out of
`.env`, which Step 5 locks to `chmod 600`, owned `deploy`. Rather than
loosen that file's permissions or duplicate the password somewhere else,
the backup cron runs as `deploy` too — the one user that can already read
it. Step 8, above, already covers the one-time setup this depends on
(creating `/var/backups/salonhub`, granting `deploy` write access to it and
to `/var/log/salonhub`, installing the cron line, and running it once by
hand to prove the whole chain works) — if you skipped straight to this
section, go do that first.

With that done, `backup.sh` runs nightly at 03:15 and produces, in
`/var/backups/salonhub`:

- `salonhub-YYYY-MM-DD.sql.gz` — a `mysqldump --single-transaction` of the
  database, so it does not lock a salon out mid-booking to take it.
- `storage-YYYY-MM-DD.tar.gz` — everything under `storage/app/public`.

Both are 14 days retained; older ones are deleted automatically. The DB
password is never passed on the `mysqldump` command line or through an
environment variable — both are readable by any user on the box for the
life of the process (`ps aux`, `/proc/<pid>/environ`) — it goes in a
`chmod 600` MySQL option file that is deleted the moment the dump finishes.
A dump or archive that fails partway is never left at its real filename
(each is written to a hidden `.tmp` name and renamed only on success), so a
truncated file can never be mistaken for a good backup later. See the
comments in `backend/docs/deploy/backup.sh` for the full detail.

**`.env` is deliberately never included in these archives.** It holds
`APP_KEY`, and `PaymentSetting.credentials` / `ReminderSetting.credentials`
are `encrypted:array` columns — bundling the key into the same tarball as
the data it protects would defeat the point of encrypting them. It also
means the DB backup alone cannot recover those two columns on a server
with a different `APP_KEY`; see "Record `APP_KEY`..." in Step 5, and
"Restoring onto a different server" below.

**How you'd know a night's backup didn't happen:** `backup.sh` touches
`/var/backups/salonhub/.last-success` as its last step, so a healthy run
always leaves that file newer than 24 hours old. Check it as part of
routine ops (Step 10 also checks it once, right after the first deploy):

```bash
sudo find /var/backups/salonhub/.last-success -mtime -1
```

`sudo` is required — `/var/backups/salonhub` is `chmod 700` owned by
`deploy` (see Step 8), so without it `find` can't even `stat` the marker
and this prints nothing regardless of whether last night's backup
succeeded. Dropping the `sudo` doesn't fail loudly; it just prints the
same "missing or stale" signal forever, on a server that's backing up
fine every night — silently defeating the whole point of having a marker
to check.

Expected: prints the path. No output (with `sudo` present) means the
marker is genuinely missing or stale — check `/var/log/salonhub/backup.log`
for why last night's run didn't finish. This repo does not wire up a
paging/alerting integration for that check; if you have existing
monitoring (cron-monitoring SaaS, a Nagios/Zabbix check, even a second
cron job that emails on failure), point it at this file — with `sudo`, or
running as `deploy`/`root` — rather than trusting silence.

Off-site replication is not configured by default — `backup.sh` has a
commented-out `rclone copy` line ready to uncomment once a remote target
(S3, Backblaze, another host, ...) is chosen. Until that line is enabled,
every backup lives on the same disk as the database it protects, which
protects against a bad migration or an accidental `DROP` but not against
losing the VPS itself.

### Restore drill

**Rehearse this once before launch, and again after any schema change.** A
backup nobody has restored is an assumption, not a backup. Everything here
runs against a disposable scratch database — nothing here ever touches the
live `salonhub` database or the live `storage/app/public` directory.

`/var/backups/salonhub` is `chmod 700` owned by `deploy` (see Step 8), so
your own sudo-capable account can't read the dump or archive out of it
directly — every step below that touches a file in that directory runs
under `sudo` for that reason, not just the MySQL commands.

1. Create the scratch database:

   ```bash
   sudo mysql -u root -e "DROP DATABASE IF EXISTS salonhub_restore_test; \
     CREATE DATABASE salonhub_restore_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   ```

2. Restore the most recent dump into it. List the directory first if you
   want a specific date rather than today's (`sudo` — see above):

   ```bash
   sudo ls -la /var/backups/salonhub
   ```

   `sudo mysql -u root` above elevates the MySQL side of a restore, but a
   plain `gunzip -c file | sudo mysql ...` does **not** elevate `gunzip` —
   it still runs as your own account, which cannot open a file in a
   `chmod 700 deploy`-owned directory, so that pipeline fails before it
   ever reaches MySQL. Elevate the whole pipeline instead:

   ```bash
   DUMP="/var/backups/salonhub/salonhub-$(date +%F).sql.gz"
   sudo bash -c "gunzip -c '$DUMP' | mysql -u root salonhub_restore_test"
   ```

3. Restore the matching upload archive to a scratch path — never over the
   live `storage/app/public`. Same reasoning as step 2: the archive is
   inside the `chmod 700` directory, so the extraction itself needs `sudo`,
   not just the destination `mkdir`:

   ```bash
   mkdir -p /tmp/salonhub-restore-test
   ARCHIVE="/var/backups/salonhub/storage-$(date +%F).tar.gz"
   sudo tar -xzf "$ARCHIVE" -C /tmp/salonhub-restore-test
   ```

   The extracted files end up root-owned (root did the extracting). That's
   fine for the `diff` in step 4: `tar` restores each archive member's own
   recorded mode, not something derived from the extracting process's
   `umask` — the uploads under `storage/app/public` are world-readable
   because they were world-readable when `backup.sh` archived them, and
   `tar` reproduces that, regardless of who runs the extraction.

4. Verify. Restoring without an error is necessary but not sufficient — a
   dump that is missing rows, or truncated mid-table, restores cleanly and
   still fails silently in production. Check both:

   **Row counts, restored vs. live, for every core table.** One query per
   side rather than one per table, so this only prompts for the live DB
   password once, not four times:

   ```bash
   READ_TABLES="SELECT 'organizations', COUNT(*) FROM organizations
   UNION ALL SELECT 'customers', COUNT(*) FROM customers
   UNION ALL SELECT 'appointments', COUNT(*) FROM appointments
   UNION ALL SELECT 'services', COUNT(*) FROM services;"

   echo "-- live --"
   mysql -u salonhub -p -h 127.0.0.1 salonhub -e "$READ_TABLES"
   echo "-- restored --"
   sudo mysql -u root salonhub_restore_test -e "$READ_TABLES"
   ```

   Expected: `live` and `restored` counts match for every table, if the
   drill runs right after the dump was taken (nothing has written to the
   live database in between). If the drill runs later, `restored` should
   equal whatever the live count *was* at dump time — never higher, and
   not lower by more than the writes that happened since.

   **A spot check that real customer data — not just row counts — survived
   the round trip.** Pick the newest appointment in the restored copy and
   compare it field-by-field against the same row live:

   ```bash
   sudo mysql -u root salonhub_restore_test -e \
     "SELECT a.id, a.booking_date, a.start_time, c.name, c.email, c.phone \
      FROM appointments a JOIN customers c ON c.id = a.customer_id \
      ORDER BY a.id DESC LIMIT 1;"
   ```

   Note the `id` it prints, then run the same query against the live
   database filtered to that id:

   ```bash
   mysql -u salonhub -p -h 127.0.0.1 salonhub -e \
     "SELECT a.id, a.booking_date, a.start_time, c.name, c.email, c.phone \
      FROM appointments a JOIN customers c ON c.id = a.customer_id \
      WHERE a.id = <id from the previous query>;"
   ```

   Expected: every column matches exactly — the customer's name, email and
   phone are intact, not truncated, not garbled by a charset mismatch.

   **Uploads restored byte-for-byte:**

   ```bash
   diff -rq /tmp/salonhub-restore-test/public /var/www/salonhub/backend/storage/app/public
   ```

   Expected: no output — every file in the restored archive is identical to
   the live copy. As with the row counts above, this only holds cleanly if
   nothing was uploaded live between the dump and the drill; a `diff`
   showing extra files present only on the live side (never the reverse)
   is expected staleness, not a bad backup — re-run the drill right after
   a fresh `backup.sh` run if you need a byte-exact comparison.

5. Clean up the scratch database and files — they are not backups
   themselves, just the drill's working copy:

   ```bash
   sudo mysql -u root -e "DROP DATABASE salonhub_restore_test;"
   sudo rm -rf /tmp/salonhub-restore-test
   ```

### Restoring onto a different server

The drill above proves the backups are readable and complete; a real
disaster recovery has three differences from it worth calling out rather
than discovering live:

- **`APP_KEY` must be restored too, out-of-band.** It's not in
  `backup.sh`'s archives by design (see above). Without the exact same key
  the app was running with, `PaymentSetting.credentials` and
  `ReminderSetting.credentials` decrypt to garbage — not an error, silently
  wrong data — on every row. Set `APP_KEY` in the new server's `.env` from
  wherever it was recorded in Step 5 *before* pointing traffic at it.
- **Run `php artisan storage:link` after restoring uploads.** `backup.sh`
  archives the real directory, `storage/app/public`; it does not (and
  cannot usefully) capture `public/storage`, which is a symlink to that
  directory, not a copy of it. A fresh server has no symlink until
  `php artisan storage:link` creates one — Step 5 runs it once during
  initial setup, but a restore onto a new box needs it run again.
- **Restore into the real `salonhub` database, not a scratch one** — and
  take the app offline (or at least stop the queue worker) first, the same
  way you would for any production data load, so nothing writes to tables
  mid-restore.

---

## Redeploying

Every subsequent deploy is just, run as `deploy` (Step 5's ownership model):

```bash
sudo -iu deploy
cd /var/www/salonhub && ./backend/docs/deploy/deploy.sh
exit
```

---

## Error monitoring (Sentry)

Task 13 wires up `sentry/sentry-laravel`. Nothing above enables it — an
empty `SENTRY_LARAVEL_DSN` (the value `env.production.example` ships with)
makes reporting a no-op, on purpose, so an operator who skips this section
gets a working app with no error reporting rather than a broken deploy.

To turn it on:

1. Create a project in Sentry and copy its DSN.
2. Set `SENTRY_LARAVEL_DSN` in `.env` to that DSN. Leave
   `SENTRY_TRACES_SAMPLE_RATE=0` — performance tracing is a deliberate,
   separate follow-up (see the comment above it in
   `docs/deploy/env.production.example`), not something to turn on as a
   side effect of setting the DSN.
3. Re-run `php artisan config:cache` (or the next `deploy.sh` run does this
   for you) — `.env` is not re-read once a config cache exists.
4. Verify delivery:

```bash
cd /var/www/salonhub/backend && php artisan sentry:test
```

Expected: no error, and the event appears in the Sentry project within a
minute.

`config/sentry.php` hardcodes `send_default_pii` off, `max_request_body_size`
to `none`, and the `cache` breadcrumb/span off — none of that is controlled
by an env var, so setting the DSN cannot accidentally widen what leaves the
server. See that file's docblock for the full analysis.

## What's not covered here

- **Off-site backup replication.** Step 11's `backup.sh` writes to
  `/var/backups/salonhub` on the same VPS it protects. Its commented-out
  `rclone copy` line is ready but not enabled by default — choose a remote
  target and turn it on before relying on backups to survive losing the
  whole server, not just its database.
- **Multi-server / high-availability deployment.** Everything above assumes
  one VPS running nginx, php-fpm, the queue worker, MySQL and Redis
  together. Splitting these across hosts is out of scope here.
- **Log rotation for `backup.log` / `worker.log`.** Both grow forever under
  `/var/log/salonhub` — nothing here installs a `logrotate` config for
  them. Ubuntu's default `logrotate` package is already present and already
  handles the system's own logs; add a `/etc/logrotate.d/salonhub` entry
  for these two before disk space run-out becomes a page.
