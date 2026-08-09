# Glowhub — configuration reference

Every key and value needed to make **email**, **notifications (SMS/WhatsApp
reminders)**, **payments** and **error monitoring** work, in local development
and in production.

Companion documents:

- [`backend/docs/deploy/README.md`](../backend/docs/deploy/README.md) — the
  server runbook (nginx, TLS, supervisor, cron, backups). This file is about
  *values*; that one is about *machines*.
- [`backend/.env.example`](../backend/.env.example) — local template.
- [`backend/docs/deploy/env.production.example`](../backend/docs/deploy/env.production.example)
  — production template.
- [`frontend/.env.example`](../frontend/.env.example) — build-time frontend
  template.

---

## 1. Where configuration actually lives

Glowhub reads settings from three places. Knowing which is which prevents most
"I set the key and nothing happened" bugs.

| Layer | Holds | Scope | Changed by |
|-------|-------|-------|-----------|
| `backend/.env` | Infrastructure + **platform** credentials: DB, Redis, SMTP, platform Twilio, Sentry, domains | Whole install | An operator, on the server |
| `frontend/.env.production` (`VITE_*`) | Values **inlined into the JS bundle at build time** | Whole install | An operator, then a rebuild |
| Database, `*_settings` tables | **Per-salon** credentials: payment gateway store id/password, a salon's own Twilio account | One organization | The salon owner, in Settings |

Two consequences worth internalising:

1. **`VITE_*` variables are baked into the bundle.** There is no runtime
   override. Change one → `npm run build` again → redeploy. Setting it in the
   backend `.env` does nothing.
2. **Per-salon credentials are encrypted with `APP_KEY`.** `PaymentSetting` and
   `ReminderSetting` both cast `credentials` to `encrypted:array`
   ([`app/Models/PaymentSetting.php`](../backend/app/Models/PaymentSetting.php),
   [`app/Models/ReminderSetting.php`](../backend/app/Models/ReminderSetting.php)).
   Rotating or losing `APP_KEY` makes every salon's gateway and Twilio
   credentials undecryptable — payments and reminders break platform-wide and
   the only fix is every salon re-entering them. Back `APP_KEY` up with the
   database, and never regenerate it on a live install.

### Which values must match across layers

| Backend | Frontend | Why |
|---------|----------|-----|
| `APP_DOMAIN` | `VITE_APP_DOMAIN` | Decides which Host is a salon. A mismatch fails **silently**: every salon subdomain renders the marketing page instead of the booking site (see the long note in `frontend/.env.example`). |
| `CONTACT_EMAIL` | `VITE_CONTACT_EMAIL` | The address the contact form delivers to *and* the one printed on the privacy/refund pages. A mismatch invites people to write to a mailbox nobody reads. |

---

## 2. Core keys (both environments)

| Key | Local | Production | Notes |
|-----|-------|-----------|-------|
| `APP_ENV` | `local` | `production` | `DemoSalonSeeder` refuses to run outside `local`/`testing`. |
| `APP_KEY` | `php artisan key:generate` | `php artisan key:generate`, once, then never again | Encrypts per-salon credentials — see above. |
| `APP_DEBUG` | `true` | `false` | `true` in production leaks stack traces and env values. |
| `APP_URL` | `http://localhost:8000` | `https://app.glowhub.com` | **Load-bearing for payments**: gateway success/fail/cancel/IPN URLs are built from it (`Public\BookingController::startGatewaySession`). |
| `APP_DOMAIN` | `glowhub.com` | your apex | Mints each salon's `<slug>.APP_DOMAIN` domain row, anchors the CORS subdomain pattern, resolves Host → tenant. Changing it after salons exist orphans their existing domain rows. |
| `FRONTEND_URL` | `http://localhost:5173` | `https://app.glowhub.com` | Used to build links in mail. |
| `CORS_ALLOWED_ORIGINS` | Vite dev origins | `https://app.glowhub.com,https://glowhub.com` | Absolute origins, comma-separated. Salon subdomains match by pattern from `APP_DOMAIN` and need **no** entry. |
| `DB_CONNECTION` | `sqlite` | `mysql` (+ host/db/user/password) | SQLite keeps a fresh checkout running with no services. |
| `QUEUE_CONNECTION` | `database` (or `sync`) | `redis` | See §3 — with no worker, queued mail is never delivered. |
| `CACHE_STORE` / `SESSION_DRIVER` | `database` | `redis` | |
| `FILESYSTEM_DISK` | `local` | `public` | Production also needs `php artisan storage:link` for logos/covers/gallery. |
| `LOG_LEVEL` | `debug` | `warning` | `debug` in production writes customer data to disk. |

After editing `.env` **in production**, always:

```bash
php artisan config:cache   # rebuilds the cached config
php artisan queue:restart  # workers hold the OLD config until restarted
```

With a cached config, bare `env()` calls outside `config/` return `null`. Read
values through `config()`.

---

## 3. Email

### What sends mail

| Mailable | Recipient | Queued? |
|----------|-----------|---------|
| `BookingConfirmationMail` | Customer | **Yes** (`ShouldQueue`) |
| `NewBookingMail` | Salon | **Yes** |
| `BookingRescheduledMail` | Customer + salon | **Yes** |
| `BookingCancelledMail` | Customer + salon | **Yes** |
| `CustomerLoginCodeMail` | Customer | No — sent inline; the customer is waiting on the code |
| `ContactMessageMail` | `CONTACT_EMAIL` | No — sent inline |

> **The queue worker is not optional.** Four of six Mailables implement
> `ShouldQueue`. With `QUEUE_CONNECTION=redis` (or `database`) and no worker
> running, zero booking confirmations, reschedules or cancellations are ever
> delivered — they sit in the queue forever, with no error anywhere.

Delivery failures are logged and swallowed by `App\Services\BookingNotifier`:
a booking is never lost because mail hiccuped, so **check the log, not the HTTP
response**, when mail goes missing.

### Local development

Easiest — write mail to the log and read `storage/logs/laravel.log`:

```dotenv
MAIL_MAILER=log
```

To see rendered HTML instead, run [Mailpit](https://mailpit.axllent.org)
(`brew install mailpit && mailpit`) and point at it:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=1025
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_FROM_ADDRESS="bookings@glowhub.test"
MAIL_FROM_NAME="Glowhub"
CONTACT_EMAIL="support@glowhub.test"
```

Web UI at <http://localhost:8025>.

Then either run a worker in a second terminal:

```bash
php artisan queue:work
```

…or set `QUEUE_CONNECTION=sync` locally so queued mail sends inline. `sync`
is the simpler default while working on booking flows; use a real worker when
you are specifically testing queue behaviour.

Tests never send: `phpunit.xml` pins `MAIL_MAILER=array` and
`QUEUE_CONNECTION=sync`.

### Production

Any real relay works — the app only needs SMTP. Postmark, Resend and SES
transports are also wired in `config/mail.php` if you prefer an API driver
(`MAIL_MAILER=postmark` + `POSTMARK_API_KEY`, `MAIL_MAILER=resend` +
`RESEND_API_KEY`, `MAIL_MAILER=ses` + the `AWS_*` keys).

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp.your-provider.com
MAIL_PORT=587
MAIL_USERNAME=<smtp user>
MAIL_PASSWORD=<smtp password>
MAIL_SCHEME=                       # blank: auto-selected from the port
MAIL_FROM_ADDRESS="bookings@glowhub.com"
MAIL_FROM_NAME="Glowhub"
CONTACT_EMAIL="support@glowhub.com"
```

- `MAIL_PORT=587` → STARTTLS (`smtp`); `465` → implicit TLS (`smtps`). Leave
  `MAIL_SCHEME` blank unless you need to force one; Symfony accepts only those
  two values.
- **`MAIL_MAILER=log` in production silently discards every message.** It is
  the framework default, so an unset `MAIL_MAILER` is the same bug.
- `MAIL_FROM_ADDRESS` must be on a domain whose SPF/DKIM/DMARC you control, or
  booking confirmations land in spam.
- `CONTACT_EMAIL` must be a **monitored role address on the product domain**,
  not a personal mailbox: the privacy page names it as the contact for
  data-subject requests and the refund page as the payment-dispute escalation
  point. Set `VITE_CONTACT_EMAIL` to the identical value and rebuild the
  frontend.

### Verify

```bash
# 1. Does the relay accept a message?
php artisan tinker --execute="Mail::raw('Glowhub smtp check', fn(\$m) => \$m->to('you@example.com')->subject('Glowhub'));"

# 2. Is a worker draining the queue? (production)
sudo supervisorctl status glowhub-worker      # RUNNING
php artisan queue:failed                       # should stay empty
```

Then make a real booking on a salon site and confirm both the customer mail and
the salon alert arrive.

---

## 4. Notifications — SMS / WhatsApp appointment reminders

### How the sender is chosen

`App\Reminders\ReminderChannelManager` picks an account per salon, in order:

1. **The salon's own Twilio credentials**, entered in Settings → Reminders
   (stored encrypted on `reminder_settings.credentials`). Own credentials are
   all-or-nothing — a salon's sender number is never mixed with the platform
   account.
2. **The platform account** from `config/services.php` → the `TWILIO_*` env
   keys. This carries every salon that has not connected its own.
3. **`LogReminderChannel`** — when neither is complete (missing SID, token, or
   both a `from` and a Messaging Service SID). Nothing is sent; the message is
   written to the log. This is the correct default for local development, and
   it is what an unconfigured production server does too.

### Platform env keys

```dotenv
TWILIO_ACCOUNT_SID=ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
TWILIO_AUTH_TOKEN=<auth token>
TWILIO_FROM=+15551234567            # SMS sender, E.164
TWILIO_WHATSAPP_FROM=+14155238886   # WhatsApp sender (sandbox number in dev)
TWILIO_MESSAGING_SERVICE_SID=MGxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx  # optional
```

- A Messaging Service **wins over** `TWILIO_FROM`: Twilio rejects a request
  carrying both, so `TwilioReminderChannel` sends `MessagingServiceSid` alone
  when it is set.
- The manager treats configuration as complete when it has `account_sid`,
  `auth_token`, **and** either a `from` or a `messaging_service_sid`. Half a
  configuration falls back to the log driver rather than failing loudly.
- WhatsApp addresses are prefixed with `whatsapp:` automatically — store plain
  E.164 numbers.

### Per-salon keys (Settings → Reminders, `PUT /api/settings/reminders`)

| Field | Values |
|-------|--------|
| `enabled` | boolean |
| `channel` | `sms` or `whatsapp` |
| `lead_hours` | 1–168 (hours before the appointment) |
| `credentials.account_sid` / `.auth_token` / `.from` / `.whatsapp_from` / `.messaging_service_sid` | the salon's own Twilio account |

### The scheduler cron is not optional

`bootstrap/app.php` schedules `reminders:send` **hourly** and
`bookings:release-abandoned` **every 15 minutes**. Neither runs on its own.
Without the cron entry, no reminder is ever sent and abandoned unpaid bookings
hold their slot forever.

```cron
* * * * * cd /var/www/glowhub/backend && php artisan schedule:run >> /dev/null 2>&1
```

Reminders are dispatched as **queued jobs**, so the worker from §3 is required
here too.

### Local development

Leave all `TWILIO_*` keys empty. Reminders route to `LogReminderChannel` and
appear in `storage/logs/laravel.log` as `[reminder] to=<phone> :: <message>`,
with no Twilio bill. Force a run without waiting for the hour:

```bash
php artisan reminders:send
php artisan queue:work --once      # unless QUEUE_CONNECTION=sync
```

To test real delivery, use a Twilio **trial** account: the sending number is
provided, and destination numbers must be verified in the Twilio console
first. For WhatsApp, the sandbox number is `+14155238886` and each recipient
must join the sandbox by sending its join code once.

### Verify

```bash
php artisan reminders:send && tail -n 50 storage/logs/laravel.log
```

Expect either `[reminder] to=…` lines (log driver — nothing was sent) or an
outbound message in the Twilio console. A `4xx` from Twilio surfaces as a
failed job, not a silent drop: `TwilioReminderChannel` calls `->throw()`.

---

## 5. Payments

**There are no payment env keys.** Gateway credentials are per-salon, entered
by the owner in Settings → Payments and stored encrypted on
`payment_settings.credentials`. The platform holds no gateway account.

### Provider

SSLCommerz hosted checkout, via `App\Services\SslcommerzGateway`:

| Mode | Base URL | Selected by |
|------|----------|-------------|
| Sandbox | `https://sandbox.sslcommerz.com` | `gateway_sandbox = true` |
| Live | `https://securepay.sslcommerz.com` | `gateway_sandbox = false` |

### Per-salon settings (`PUT /api/settings/payments`)

| Field | Meaning |
|-------|---------|
| `deposit_type` | `none`, `percent` or `fixed` |
| `deposit_value` | Percentage, or a fixed amount in the salon's currency |
| `manual_enabled`, `manual_account_number`, `manual_instructions` | Offline transfer (bKash/bank) instructions shown at checkout |
| `gateway` | `sslcommerz` or none |
| `gateway_sandbox` | boolean — sandbox vs live endpoint |
| `credentials.store_id` | SSLCommerz store id |
| `credentials.store_passwd` | SSLCommerz store password |

`gatewayEnabled()` is true only when the provider is `sslcommerz` **and** both
`store_id` and `store_passwd` are present. Otherwise online deposits are not
offered — the salon falls back to manual payment, or takes no deposit.

### Callback URLs — the one env dependency

`Public\BookingController::startGatewaySession` builds all four callback URLs
from **`config('app.url')`**:

```
{APP_URL}/api/public/{slug}/payment/{tran}/callback/success
{APP_URL}/api/public/{slug}/payment/{tran}/callback/fail
{APP_URL}/api/public/{slug}/payment/{tran}/callback/cancel
{APP_URL}/api/public/{slug}/payment/{tran}/ipn
```

Two things follow:

1. **`APP_URL` must be a publicly reachable HTTPS origin in production.** A
   stale or `http://localhost` value sends paying customers to a dead URL after
   they have been charged. The IPN endpoint records the payment even when the
   customer never returns to the browser — but only if SSLCommerz can reach it.
2. **Local gateway testing needs a tunnel.** `http://localhost:8000` is not
   reachable from SSLCommerz. Run a tunnel and point `APP_URL` at it for the
   session:

   ```bash
   cloudflared tunnel --url http://localhost:8000     # or: ngrok http 8000
   # then in backend/.env:
   APP_URL=https://<your-tunnel-host>
   php artisan config:clear
   ```

   Use SSLCommerz **sandbox** store credentials with `gateway_sandbox` on.
   Without a tunnel you can still exercise the deposit/manual-payment logic —
   just not the hosted-checkout round trip.

Never trust the browser-posted callback fields: `validate()` re-confirms the
transaction server-to-server by `val_id` before the payment is marked paid.
Refunds go through `merchantTransIDvalidationAPI.php` and are processed
asynchronously by SSLCommerz.

### Abandoned checkouts

```dotenv
GATEWAY_PENDING_TTL_MINUTES=30   # config/booking.php default
```

A checkout that is opened and never completed holds the appointment slot.
`bookings:release-abandoned` (scheduled every 15 minutes — see §4) cancels
bookings whose unpaid gateway payment is older than this TTL. Without the cron,
slots leak.

### Verify

```bash
php artisan bookings:release-abandoned   # runs clean, reports nothing to do
```

End-to-end: enable a deposit + sandbox gateway on a test salon, book through
the public site, pay with an SSLCommerz sandbox card, and confirm the
appointment flips from pending to confirmed with the payment recorded.

---

## 6. Error monitoring (Sentry) — optional

```dotenv
SENTRY_LARAVEL_DSN=              # empty = disabled entirely; nothing is sent
SENTRY_TRACES_SAMPLE_RATE=0      # performance tracing off
SENTRY_ENVIRONMENT=production    # optional; defaults to APP_ENV
SENTRY_RELEASE=                  # optional; e.g. the deploy's git sha
```

Leave the DSN empty in local development — the reporter becomes a no-op and
nothing needs guarding.

`config/sentry.php` **hardcodes** the privacy-critical switches so no env
misconfiguration can widen what leaves the server: `send_default_pii` off,
`max_request_body_size` `none` (otherwise OTP codes, login passwords and
gateway callback bodies ride along), cache breadcrumbs/spans off (rate-limiter
keys contain customer emails and IPs), log breadcrumbs off (the reminder log
line contains the customer's phone number), plus a `before_send` hook that
strips URLs, query strings and stack-frame arguments. Do not make those
env-driven.

`SENTRY_TRACES_SAMPLE_RATE=0` is deliberate: the tracing path has not had the
same PII review as the error path. Raising it is a reviewed follow-up, not a
side effect of setting a DSN.

Verify: `php artisan sentry:test`.

---

## 7. Frontend build-time keys

`frontend/.env.production` (or exported in the build environment — `deploy.sh`
exports `VITE_APP_DOMAIN`):

```dotenv
VITE_APP_DOMAIN=glowhub.com          # MUST equal backend APP_DOMAIN
VITE_CONTACT_EMAIL=support@glowhub.com  # MUST equal backend CONTACT_EMAIL
# VITE_API_URL=https://app.glowhub.com/api   # only if the API is on another origin
```

- `VITE_API_URL` is genuinely optional: both production vhosts serve the SPA
  and the API from one origin, so the same-origin `/api` default is right and
  needs no CORS. In local development, Vite proxies `/api` and `/storage` to
  `http://127.0.0.1:8000` (see `frontend/vite.config.js`) — do not set it.
- A wrong `VITE_APP_DOMAIN` throws no error; it just serves the marketing page
  where every salon's booking site should be. Treat a mismatch as a P1.
- Rebuild (`npm run build`) after any change here. Nothing is read at runtime.

---

## 8. Checklists

### Fresh local setup

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed --class=DemoSalonSeeder   # optional demo salon
php artisan serve                              # :8000

cd ../frontend
npm install
npm run dev                                    # :5173
```

Defaults give you SQLite, `MAIL_MAILER=log`, no Twilio (log reminders) and no
gateway — the whole app runs with no external account. Add, only as needed:
Mailpit (§3), a Twilio trial account (§4), a tunnel plus SSLCommerz sandbox
credentials (§5).

### Production go-live

- [ ] `APP_KEY` generated **once**, and backed up alongside the database
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=warning`
- [ ] `APP_URL` is the real public HTTPS origin (payment callbacks depend on it)
- [ ] `APP_DOMAIN` == `VITE_APP_DOMAIN`, wildcard DNS `*` record in place
- [ ] `CORS_ALLOWED_ORIGINS` lists the apex and app origins
- [ ] `MAIL_MAILER` is a real relay (**not** `log`), from-domain has SPF/DKIM
- [ ] `CONTACT_EMAIL` == `VITE_CONTACT_EMAIL`, and someone actually reads it
- [ ] Queue worker running under supervisor (`glowhub-worker`)
- [ ] `schedule:run` cron installed
- [ ] `php artisan storage:link` run (`FILESYSTEM_DISK=public`)
- [ ] `php artisan config:cache` after the final `.env` edit
- [ ] Platform `TWILIO_*` set, or accepted that reminders only log
- [ ] Test booking placed end-to-end: emails delivered, reminder queued,
      deposit charged and recorded

---

## 9. Troubleshooting

| Symptom | Likely cause |
|---------|--------------|
| No booking emails, no error anywhere | No queue worker (4 Mailables are `ShouldQueue`), or `MAIL_MAILER=log`/unset |
| Login code arrives but confirmations don't | Confirms the above: the code mail is inline, the rest are queued |
| Reminders never send | Missing `schedule:run` cron; or incomplete Twilio config falling back to the log driver; or no worker |
| Reminders log `[reminder] to=…` | Log driver is active — SID, token, or sender missing at both salon and platform level |
| Twilio 400 "both From and MessagingServiceSid" | Both were configured; the Messaging Service should win — check for a stale `from` |
| Every salon subdomain shows the marketing page | `VITE_APP_DOMAIN` ≠ served domain; rebuild the frontend |
| Contact form mail goes nowhere | `CONTACT_EMAIL` unset/unmonitored, or diverges from `VITE_CONTACT_EMAIL` |
| Customer charged, booking still pending | `APP_URL` wrong or unreachable → callback and IPN never arrived |
| Slots stuck on unpaid bookings | `bookings:release-abandoned` not running (cron), or `GATEWAY_PENDING_TTL_MINUTES` too high |
| "Gateway not enabled" despite credentials | `gateway` is not `sslcommerz`, or one of `store_id`/`store_passwd` is blank |
| All salons' gateway/Twilio credentials suddenly invalid | `APP_KEY` changed — encrypted `credentials` can no longer be decrypted |
| `.env` edit had no effect in production | Cached config; run `php artisan config:cache` and `php artisan queue:restart` |
