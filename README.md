# SalonHub

SalonHub is a multi-tenant SaaS platform that lets salons and beauty
parlors spin up their own online booking website and manage appointments,
staff, services and customers from a single dashboard — instead of juggling
bookings through Facebook Messenger or WhatsApp.

Each organization gets a branded subdomain (`<slug>.APP_DOMAIN`, e.g.
`beautyqueen.salonhub.com`) as well as a `/salon/<slug>` path on the apex
domain, both serving the same public booking site backed by a single
shared database (every tenant-scoped table carries an `organization_id`).

## Stack

- **Backend:** Laravel 12, PHP (`^8.2` floor in `composer.json`; CI and
  production run 8.4), MySQL, Redis
- **Frontend:** Vue 3.5, Vite 8, Pinia, Vue Router, Tailwind CSS
- **Infra:** Nginx, Supervisor, cron — see the deploy runbook below

## Local setup

### Backend (`backend/`)

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

`.env.example` defaults to a local SQLite connection and log-driver mail,
so the four commands above are enough to get a working API — no MySQL or
Redis required for local dev. `APP_DOMAIN` (default `salonhub.com`)
controls what subdomain each organization is served on.

Optionally seed a fully populated demo salon (services, staff, customers,
appointments):

```bash
php artisan db:seed --class=DemoSalonSeeder
```

Login with `demo@salonhub.com` / `password` afterwards. Two things to know
before running it:

- It **refuses to run unless `APP_ENV` is `local` or `testing`** — it
  won't do anything on a server, by design.
- It's destructive on its own data: each run deletes and recreates the
  demo organization it created last time, so it's safe to re-run whenever
  you want fresh demo data.

### Frontend (`frontend/`)

```bash
cd frontend
npm install
npm run dev
```

Requires Node `^22.18.0` or `>=24.12.0` (see `frontend/package.json`
`engines`).

## Tests

```bash
# Backend (PHPUnit via Artisan)
cd backend
php artisan test

# Frontend (Vitest)
cd frontend
npm run test:unit
```

## CI

`.github/workflows/ci.yml` runs on every push to `main` and every pull
request, in two independent jobs:

- **backend:** installs Composer dependencies on PHP 8.4, runs
  `php artisan test`, then checks formatting with `./vendor/bin/pint --test`.
- **frontend:** installs npm dependencies on Node 22, runs `npm run build`,
  then `npm run test:unit`.

## Documentation

- [`CLAUDE.md`](CLAUDE.md) — the product brief: vision, tenant model, MVP
  feature scope and roadmap.
- [`docs/configuration.md`](docs/configuration.md) — every env key and
  per-salon setting behind email, SMS/WhatsApp reminders, payments and error
  monitoring, for local development and production.
- [`backend/docs/deploy/README.md`](backend/docs/deploy/README.md) — the
  production deployment runbook (nginx, the queue worker, the scheduler
  cron, backups and the restore drill).
- [`docs/superpowers/plans/`](docs/superpowers/plans/) — implementation
  plans for past and in-flight feature work.
