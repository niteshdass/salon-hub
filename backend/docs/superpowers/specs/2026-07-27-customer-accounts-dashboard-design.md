# Customer Accounts & Dashboard — Design

**Date:** 2026-07-27
**Module:** #4 (after Time-off/holidays, Reviews & ratings, Reports & analytics)

## Goal

Give the salon's customers a single platform-wide, passwordless account so they can
log in and see all their bookings across every salon they've used, cancel or
reschedule upcoming bookings, and leave reviews for completed ones.

## Summary of decisions

- **Account scope:** platform-wide. One customer identity spans all salons (orgs). The
  dashboard aggregates bookings from every salon the person has used.
- **Identity linking:** email-verified auto-claim. The account is keyed by a unique
  email; on successful email verification (via OTP) every per-salon `customers` row whose
  email matches is linked to the account. Ownership proof = control of the inbox.
- **Dashboard capabilities:** view bookings (upcoming + past), cancel / reschedule
  upcoming, leave / view reviews. **No** profile-edit surface.
- **Auth:** passwordless. Enter email → 6-digit OTP code emailed → enter code → logged in.
  No password, no separate register step (first successful verify creates + claims the
  account). Separate Sanctum guard from staff.
- **Mail:** reuse the existing Laravel mail stack (transport is env-configured — `log` in
  dev). OTP sent on-demand with `Mail::to($email)->send(...)`, mirroring `BookingNotifier`.

## Architecture

Customers already exist as **per-organization** `customers` rows (one row per salon,
deduped by phone within a salon, `email` nullable). They currently have no auth: a
booking is self-managed through a per-appointment `public_token` email link.

This module adds a **global** identity layer on top:

- A new `CustomerAccount` model (global — no `BelongsToOrganization`), authenticated by
  Sanctum on its own `customer` guard.
- A new `customer_login_codes` table backing the passwordless OTP flow.
- A nullable `customer_account_id` FK on `customers` linking each per-salon row to the
  global account that owns it.

Customer-facing API routes deliberately run **without** the `tenant` middleware, so no
tenant is bound and the `BelongsToOrganization` global scope is inert — this is what lets
one query read a customer's bookings across all salons. Because the scope is inert, every
customer query is **manually** filtered by the account's own `customers` rows. That manual
filter is the isolation guarantee and is covered by a dedicated test.

### Tech stack

Laravel 12 / PHP 8.4, Sanctum (multi-guard), Vue 3 + Tailwind + Vite. Reuses existing
`SlotGenerator`, cancel/reschedule/review logic, and `BookingNotifier`.

## Global Constraints

- **PHP 8.4 / Laravel 12.** Match existing code style and conventions.
- **Multi-tenancy is sacred.** Customer routes are cross-org by design; every customer
  data query MUST be filtered by the authenticated account's own `customers` rows
  (`customer_account_id = $account->id`). No query may return a row the account does not own.
- **Guard separation.** A staff (`User`) token MUST NOT authenticate on customer routes,
  and a customer (`CustomerAccount`) token MUST NOT authenticate on staff routes. Enforced
  by pinning each Sanctum guard to its provider model.
- **No secrets in source.** No credentials hardcoded or committed. OTP codes stored hashed.
- **TDD.** Every endpoint and behavior has a feature test written first, using real
  Sanctum bearer tokens (codebase convention), `Mail::fake()` for email assertions.
- **Passwordless, no password column** on `customer_accounts`.
- **Reuse, don't fork.** Cancel / reschedule / review reuse the existing booking engine;
  the only new authorization is account ownership of the appointment.

---

## Data model

### Table: `customer_accounts` (new, global)

| column | type | notes |
|--------|------|-------|
| `id` | bigint PK | |
| `name` | string nullable | prefilled from a claimed `customers` row when available |
| `email` | string, **unique** | the identity key |
| `phone` | string nullable | |
| `email_verified_at` | timestamp nullable | set on first successful OTP verify |
| `created_at`/`updated_at` | timestamps | |

Model `App\Models\CustomerAccount`:
- `use HasApiTokens, Notifiable;`
- **No** `BelongsToOrganization`.
- `$fillable = ['name','email','phone','email_verified_at']`.
- `$hidden = []` (no password / remember_token).
- `casts: ['email_verified_at' => 'datetime']`.
- `customers(): HasMany` → `Customer` (foreign key `customer_account_id`), used only in
  code that has already suppressed the tenant scope (customer routes have no tenant bound).

### Table: `customer_login_codes` (new)

| column | type | notes |
|--------|------|-------|
| `id` | bigint PK | |
| `email` | string, indexed | keyed by email — account may not exist yet |
| `code_hash` | string | `Hash::make(code)` — never store the plaintext code |
| `expires_at` | timestamp | request time + 10 minutes |
| `attempts` | unsigned int, default 0 | wrong-code counter; max 5 |
| `consumed_at` | timestamp nullable | set when a code successfully logs in |
| `created_at`/`updated_at` | timestamps | |

Model `App\Models\CustomerLoginCode` (plain model, no tenant scope). Helper scopes:
`active()` = `whereNull('consumed_at')->where('expires_at','>',now())`.

### Column: `customers.customer_account_id` (new, nullable FK)

`->foreignId('customer_account_id')->nullable()->constrained('customer_accounts')->nullOnDelete()`.
Add to `Customer::$fillable`. Add `account(): BelongsTo` relation.

---

## Auth — passwordless OTP

### `POST /api/customer/auth/request-code`

Body: `{ email }`. Throttled `throttle:6,1` (per-IP) **and** guarded so a single email
can't be spammed (reuse a per-email limiter, e.g. RateLimiter keyed by lowercased email,
max 5 / 10 min).

Behavior:
1. Validate `email` (required, email).
2. Generate a 6-digit numeric code (`str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT)`).
3. Create a `customer_login_codes` row: `email` (lowercased), `code_hash = Hash::make($code)`,
   `expires_at = now()->addMinutes(10)`, `attempts = 0`.
4. Send the code to the email with `Mail::to($email)->send(new CustomerLoginCodeMail($code))`.
5. **Always** return `200 { "message": "If that email is valid, a code has been sent." }`
   — do not reveal whether an account exists.

### `POST /api/customer/auth/verify-code`

Body: `{ email, code }`. Throttled `throttle:10,1`.

Behavior:
1. Validate `email` (required, email), `code` (required, 6 digits).
2. Fetch the newest `active()` login code for the lowercased email. If none → 422
   `{ message: 'Invalid or expired code.' }`.
3. If `attempts >= 5` → 429 `{ message: 'Too many attempts. Request a new code.' }`.
4. `Hash::check($code, $row->code_hash)`? If **no** → `increment('attempts')`, return 422
   `Invalid or expired code.`.
5. If **yes**:
   - `update(['consumed_at' => now()])`.
   - `CustomerAccount::firstOrCreate(['email' => $email], [])` → set `email_verified_at = now()`, save.
   - **Auto-claim** (§ Isolation): link matching `customers` rows.
   - If the account's `name` is null, backfill from a claimed row's name if present.
   - Issue token: `$account->createToken('customer')->plainTextToken`.
   - Return `200 { token, account: { id, name, email, phone } }`.

### `GET /api/customer/auth/me` (`auth:customer`)

Return `{ account: { id, name, email, phone } }`.

### `POST /api/customer/auth/logout` (`auth:customer`)

`$request->user()->currentAccessToken()->delete();` → `200 { message: 'Logged out.' }`.

### Guard config (`config/auth.php`)

```php
'guards' => [
    'web' => ['driver' => 'session', 'provider' => 'users'],
    // Pin the staff Sanctum guard to the users provider so a customer token is rejected.
    'sanctum'  => ['driver' => 'sanctum', 'provider' => 'users'],
    'customer' => ['driver' => 'sanctum', 'provider' => 'customers'],
],
'providers' => [
    'users'     => ['driver' => 'eloquent', 'model' => env('AUTH_MODEL', User::class)],
    'customers' => ['driver' => 'eloquent', 'model' => App\Models\CustomerAccount::class],
],
```

Sanctum validates a token's `tokenable` against the guard's provider model, so
`auth:customer` only accepts `CustomerAccount` tokens and `auth:sanctum` only accepts
`User` tokens. Existing staff routes stay on `auth:sanctum` unchanged.

**Guard-separation test is mandatory:** a `User` token → any `auth:customer` route → 401;
a `CustomerAccount` token → any `auth:sanctum` staff route → 401. The test is the real
gate — if the installed Sanctum version does not enforce the provider-model check by
config alone, add a tiny middleware that 401s when `$request->user()` is not the expected
model class, and apply it to the respective route groups. Either way both directions of
the test MUST pass.

---

## Isolation & linking (security crux)

### Auto-claim (idempotent, on every successful verify)

```php
Customer::whereNull('customer_account_id')
    ->where('email', $account->email)     // tenant scope inert here (no tenant bound)
    ->update(['customer_account_id' => $account->id]);
```

Runs inside `verify-code`. Idempotent: rows already linked are skipped by the
`whereNull`. Newly created rows (e.g. a booking made since last login) get linked at the
next verify.

### Fresh-booking attach (public `book()`)

In `Public\BookingController::book()`, right after the phone `firstOrCreate($customer)`:
if that customer has a non-null `email` matching a **verified** account, set the link
immediately so the booking appears on the dashboard without waiting for a re-login.

```php
if ($customer->email && ! $customer->customer_account_id) {
    $accountId = CustomerAccount::whereNotNull('email_verified_at')
        ->where('email', $customer->email)->value('id');
    if ($accountId) { $customer->customer_account_id = $accountId; $customer->save(); }
}
```

A booking whose email/phone identity differs from any account simply stays unlinked
(correct — it isn't that account's booking).

### Every read filters by ownership

```php
$customerIds = Customer::where('customer_account_id', $account->id)->pluck('id');
Appointment::whereIn('customer_id', $customerIds)->...;
```

A dedicated **cross-account isolation** test seeds two accounts each with their own salon
booking and asserts each sees only its own — the guarantee that the inert global scope
does not leak.

---

## Dashboard endpoints (all `auth:customer`)

### `GET /api/customer/bookings`

Response:
```json
{
  "data": {
    "upcoming": [ Booking, ... ],
    "past": [ Booking, ... ]
  }
}
```

`Booking` shape (per appointment the account owns):
```json
{
  "id": 12,
  "salon": { "id": 3, "name": "Acme Salon", "slug": "acme" },
  "service": "Stylish Haircut",
  "staff": "Sam Stylist",
  "branch": "Downtown",
  "booking_date": "2026-08-02",
  "start_time": "15:00",
  "end_time": "15:45",
  "status": "confirmed",
  "price": "40.00",
  "amount_paid": "10.00",
  "balance_due": "30.00",
  "can_manage": true,
  "review": { "rating": 5, "comment": "...", "status": "published" } | null,
  "can_review": false
}
```

Splitting rule:
- **upcoming** = `booking_date >= today` AND status ∈ {pending, confirmed}. Ordered by
  `booking_date` asc, then `start_time` asc.
- **past** = everything else (past dates, completed, cancelled, no-show). Ordered by
  `booking_date` desc, then `start_time` desc.

`can_manage` = upcoming and status ∈ {pending, confirmed}.
`can_review` = status == completed AND no existing review for the appointment.
`review` = the appointment's review if present (any status), else null.

Eager-load `organization`, `service`, `staff`, `branch`, `review`, `payments` to avoid N+1.

### `POST /api/customer/bookings/{appointment}/cancel`

1. Resolve `$appointment` by id (no route-model tenant scoping — resolve explicitly).
2. Ownership: `$appointment->customer?->customer_account_id === $account->id` else `404`.
3. Bind that appointment's organization as the current tenant.
4. Reuse the existing cancel logic (status → cancelled, notifier). Respect the same rules
   the token-based `Public\BookingController::cancel` enforces (e.g. cannot cancel a
   completed/cancelled booking → 422).
5. Return the updated `Booking`.

### `GET /api/customer/bookings/{appointment}/slots?date=YYYY-MM-DD`

1. Resolve + ownership (404 on miss) + must be manageable (else 422).
2. Bind the appointment's organization as current tenant.
3. Return available slots for the appointment's service/staff/branch on `date` using the
   existing `SlotGenerator` (same output shape the public `slots` endpoint returns).

### `POST /api/customer/bookings/{appointment}/reschedule`

Body: `{ date, start_time }`.
1. Resolve + ownership (404) + manageable (422).
2. Bind org as current tenant.
3. Reuse the existing reschedule logic (validate the slot is still free, recompute
   `end_time` from service duration, update, notify). Same failure modes as the token
   reschedule (slot taken → 422).
4. Return the updated `Booking`.

### `POST /api/customer/bookings/{appointment}/review`

Body: `{ rating (1..5), comment (nullable, max len per existing rule) }`.
1. Resolve + ownership (404).
2. Must be `completed` AND not already reviewed, else `422`.
3. Bind org as current tenant.
4. Reuse the existing public review creation (same default status the public review flow
   uses). Return the created review.

**Action mechanics (shared):** resolve appointment by id → ownership-check → bind the
appointment's `organization` on `CurrentTenant` for the handler body → delegate to the
existing service/logic. Tenant is bound only **after** ownership passes.

---

## Frontend

### Store: `src/stores/customerAuth.js` (new)

Isolated from staff `auth.js`. Distinct `localStorage` key (e.g. `customer_token`). State:
`token`, `account`. Actions: `requestCode(email)`, `verifyCode(email, code)`,
`fetchMe()`, `logout()`. Axios calls send the customer bearer token (own instance or
per-call header) so it never collides with the staff token.

### Routes (`src/router/index.js`)

- `/account/login` → `CustomerLoginView` (public). Two-step: email → code.
- `/account` → `CustomerDashboardView`, `meta: { requiresCustomerAuth: true }`, wrapped in
  a light `CustomerLayout` (own header, "Log out"). Not the staff `DashboardLayout`.
- Router guard: a `requiresCustomerAuth` route with no customer token → redirect
  `/account/login`. Keep the existing staff `requiresAuth` guard independent.

### Views

- **`CustomerLoginView.vue`** — step 1: email field → `requestCode`. Step 2: 6-digit code
  field → `verifyCode` → on success store token, redirect `/account`. Resend link,
  error + throttle messaging.
- **`CustomerDashboardView.vue`** — fetch `GET /customer/bookings`. Two sections
  (Upcoming, Past). Each booking is a card: salon name, service, staff, branch,
  date/time, status chip, price / paid / due. Actions on manageable cards: **Reschedule**
  (opens a modal: date picker → slot list from `.../slots` → confirm), **Cancel**
  (confirm dialog). Completed cards with `can_review`: **Leave review** modal
  (rating + comment). Already-reviewed cards show the ★ rating + comment.

### Entry point

Add a "Manage my bookings / Log in" link on the salon site (`SalonSiteView`) and the
booking-confirmation screen → `/account/login`.

---

## Testing

Feature tests (real Sanctum tokens; `Mail::fake()`):

**Auth**
- request-code creates a hashed code row + sends `CustomerLoginCodeMail` (assert `Mail::assertSent`), returns generic 200.
- verify-code with the correct code creates the account, sets `email_verified_at`, returns a token.
- verify-code is passwordless-idempotent: a second login for the same email reuses the same account row.
- expired code → 422; wrong code increments `attempts` and returns 422; `attempts >= 5` → 429.
- code is single-use (`consumed_at`) — a consumed code can't be reused.

**Linking / isolation**
- verify-code auto-claims all `customers` rows across salons matching the email; non-matching rows untouched.
- fresh-booking attach: a `book()` with an email matching a verified account sets `customer_account_id`.
- **cross-account isolation:** account A and account B each own a booking at different salons; A's `GET /bookings` shows only A's, B's shows only B's.

**Guard separation**
- staff `User` token → `GET /api/customer/bookings` → 401.
- customer token → a staff `auth:sanctum` route (e.g. `GET /api/dashboard`) → 401.

**Dashboard + actions**
- bookings split upcoming vs past with the documented ordering and shape; `can_manage` / `can_review` / `review` correct.
- cancel: owner cancels an upcoming booking (status → cancelled); cancelling a completed/cancelled booking → 422; a foreign appointment → 404.
- slots + reschedule: owner reschedules to a free slot; taken slot → 422; foreign appointment → 404.
- review: owner reviews a completed booking; reviewing a non-completed booking → 422; a second review → 422; foreign appointment → 404.

**Frontend**
- `npm run build` clean, `CustomerDashboardView` + `CustomerLoginView` chunks emitted.

## Out of scope (YAGNI)

- Profile editing (name/phone/email/password changes).
- Password login / social login.
- Cross-salon "book again" shortcut.
- Merging duplicate accounts, phone-based claiming, admin management of customer accounts.
