# SalonHub v1 Release Readiness — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close every gap between today's feature-complete codebase and a v1 that a real salon can sign up for, get bookings on, and that we can operate in production.

**Architecture:** No new subsystems. Three kinds of work: (a) correctness fixes to shipped code, (b) missing onboarding/legal surface a paying customer requires, (c) the deploy/ops layer that does not exist at all. Tasks are ordered so the suite goes green first, then security, then product, then infra.

**Tech Stack:** Laravel 12 / PHP 8.4 / MySQL (prod) + sqlite (test) / Sanctum · Vue 3 / Pinia / Vue Router / Tailwind v4 / Vite · Nginx + Supervisor + cron on a DigitalOcean VPS, Cloudflare in front.

## Global Constraints

- Backend commands run from `/Users/niteshdas/Projects/salon-hub/backend`. Frontend commands from `/Users/niteshdas/Projects/salon-hub/frontend`.
- Full backend suite must be green at the end of every task: `php artisan test`. Baseline at plan start: **309 passed, 2 failed** (Task 1 fixes the 2).
- Frontend must build clean at the end of every frontend task: `npm run build`.
- TDD: write the failing test, watch it fail for the right reason, then implement. Infra tasks that cannot carry a PHPUnit test state their manual verification command explicitly.
- Multi-tenancy is sacred: no query may return a row belonging to another `organization_id`. Any new query goes through the tenant global scope or an explicit `organization_id` filter.
- No secrets in source. Anything environment-specific goes to `.env` and is documented in `.env.example`.
- One commit per task, conventional-commit prefix (`fix:`, `feat:`, `chore:`, `docs:`).
- Do not reformat or restructure files outside the task's stated scope.

---

## File Structure

Files created or modified across the plan, by responsibility:

**Backend — correctness & security**
- `tests/Feature/Reports/ReportsTest.php` — freeze time so rating-window tests stop rotting (T1)
- `routes/api.php` — throttles on `auth/login`, `auth/register` (T3); SPA fallback lives in `routes/web.php` (T7)
- `app/Actions/Auth/RegisterOrganization.php` — create the default branch (T4)
- `app/Http/Controllers/Public/SiteController.php` — hours read from `branches.opening_hours_json` (T5)

**Backend — config & ops**
- `config/cors.php` — env-driven origins + wildcard subdomain pattern (T8)
- `.env.example` / `docs/deploy/env.production.example` (T9)
- `docs/deploy/` — nginx vhosts, supervisor program, cron line, runbook (T10)
- `docs/deploy/backup.sh` — nightly mysqldump + storage tar (T14)
- `config/logging.php`, `config/services.php` — Sentry DSN wiring (T13)
- `database/seeders/DemoSalonSeeder.php` (T16)

**Frontend**
- `src/layouts/AuthLayout.vue` + 5 auth views + `src/assets/main.css` — already written, uncommitted (T2)
- `src/views/legal/TermsView.vue`, `PrivacyView.vue`, `RefundView.vue`; `src/components/marketing/MarketingFooter.vue`; `src/router/index.js` (T6)
- `src/lib/tenantHost.js` + `src/router/index.js` — resolve a salon from the host header (T12)
- `vitest.config.js`, `src/**/*.spec.js` (T15)

**Repo hygiene**
- `.github/workflows/ci.yml` (T11)
- delete `frontend/src/assets/base.css`, rename `# CLAUDE.md` → `CLAUDE.md`, delete `login.png` (T2, T17)

---

### Task 1: Stop the rating-report tests rotting with the calendar

Two tests in `ReportsTest` were written in July with hardcoded July report windows, but they create their `Review` rows at real `now()`. `ReportService::staffRatings()` filters reviews by `created_at` inside the requested range (`app/Services/ReportService.php:275-276`), so from August onward the review falls outside the window and the average comes back `0.0`. The product behaviour is correct — the *tests* encode a date assumption they never froze.

**Files:**
- Test: `tests/Feature/Reports/ReportsTest.php` (methods `test_staff_performance_with_rating_in_range`, `test_staff_rating_excludes_hidden_reviews`)

**Interfaces:**
- Consumes: nothing.
- Produces: a green suite. Every later task's "full suite green" gate depends on this.

- [ ] **Step 1: Reproduce the failure and read it**

Run: `php artisan test tests/Feature/Reports/ReportsTest.php`
Expected: 2 failures, both `Failed asserting that 0.0 is identical to 5.0.`

- [ ] **Step 2: Confirm the cause is `created_at`, not the aggregate**

Run: `grep -n "created_at" app/Services/ReportService.php`
Expected: lines 275-276 show `->whereDate('created_at', '>=', $from)` / `'<=', $to`. This confirms the review must be *created* inside the window, and the tests create it today.

- [ ] **Step 3: Freeze the clock inside the two tests**

In `test_staff_performance_with_rating_in_range` and `test_staff_rating_excludes_hidden_reviews`, wrap the `Review::create(...)` calls so the rows land inside the report window. Add this line immediately before the first `Review::create` in each test:

```php
$this->travelTo('2026-07-15 10:00:00');
```

and immediately after the last `Review::create` in each test:

```php
$this->travelBack();
```

`travelTo` moves Carbon's clock, so `created_at` is written as `2026-07-15`, inside the `from=2026-07-01&to=2026-07-31` window the test already queries. `travelBack` restores real time before the HTTP request so nothing else in the test is affected.

- [ ] **Step 4: Verify both tests pass and stay passing next month**

Run: `php artisan test tests/Feature/Reports/ReportsTest.php`
Expected: all ReportsTest tests PASS.

Then prove the fix is date-independent rather than accidentally passing:

Run: `TZ=UTC php artisan test tests/Feature/Reports/ReportsTest.php --filter test_staff_rating_excludes_hidden_reviews`
Expected: PASS.

- [ ] **Step 5: Full suite**

Run: `php artisan test --compact`
Expected: `Tests: 311 passed` — zero failures.

- [ ] **Step 6: Commit**

```bash
git add backend/tests/Feature/Reports/ReportsTest.php
git commit -m "fix: freeze clock in staff-rating report tests so they stop rotting"
```

---

### Task 2: Land the pending auth-screen redesign, clear the working tree

Six frontend files are modified and one is untracked (`AuthLayout.vue`), plus a stray `login.png` screenshot sits in the repo root. Nothing else can be reviewed cleanly until the tree is clean.

**Files:**
- Create (already written, untracked): `frontend/src/layouts/AuthLayout.vue`
- Modify (already written, uncommitted): `frontend/src/assets/main.css`, `frontend/src/views/LoginView.vue`, `RegisterView.vue`, `ForgotPasswordView.vue`, `ResetPasswordView.vue`, `VerifyEmailView.vue`
- Delete: `login.png` (repo root)

**Interfaces:**
- Consumes: nothing.
- Produces: `AuthLayout.vue` as the shared shell for all five auth screens. Task 6's legal pages and Task 12's host resolver both assume a clean tree to diff against.

- [ ] **Step 1: Read every uncommitted change before deciding to keep it**

Run: `git diff -- frontend/src && git status --short`
Read the whole diff. Confirm each of the five views now renders inside `AuthLayout` and that no API call, route name, or store action changed — this was a visual redesign only.

- [ ] **Step 2: Verify the redesign builds and the auth flow still works**

Run: `cd frontend && npm run build`
Expected: `✓ built in …`, no errors, `LoginView`/`RegisterView` chunks emitted.

- [ ] **Step 3: Smoke the flow against a running backend**

Run backend: `php artisan serve` (separate shell). Run frontend: `npm run dev`.
Visit `/login`, `/register`, `/forgot-password`. Confirm each renders in the new layout and that submitting `/login` with bad credentials still shows the inline error.

- [ ] **Step 4: Drop the stray screenshot**

`login.png` is a design reference, not source. Delete it and make sure it cannot come back:

```bash
rm login.png
```

Then confirm `.gitignore` covers loose screenshots — append if the line is absent:

```
*.png
!frontend/src/assets/*.png
!frontend/public/*.png
```

- [ ] **Step 5: Commit**

```bash
git add frontend/src/layouts/AuthLayout.vue frontend/src/assets/main.css \
        frontend/src/views/LoginView.vue frontend/src/views/RegisterView.vue \
        frontend/src/views/ForgotPasswordView.vue frontend/src/views/ResetPasswordView.vue \
        frontend/src/views/VerifyEmailView.vue .gitignore
git commit -m "feat: shared auth layout across login, register and password screens"
```

- [ ] **Step 6: Confirm the tree is clean**

Run: `git status --short`
Expected: empty output.

---

### Task 3: Rate-limit login and registration

`routes/api.php` throttles password reset, email verification and the customer OTP endpoints — but `auth/login` and `auth/register` carry no throttle, and neither `AuthController` nor `LoginRequest` limits attempts. Login is open to credential brute-force; register is open to mass org-signup spam. This is the last unguarded unauthenticated write in the app.

**Files:**
- Modify: `backend/routes/api.php:39-40`
- Modify: `backend/app/Http/Controllers/Auth/AuthController.php` (login method, per-email limiter)
- Test: `backend/tests/Feature/Auth/LoginThrottleTest.php` (create)

**Interfaces:**
- Consumes: `App\Http\Controllers\Auth\AuthController::login(LoginRequest $request): JsonResponse` — unchanged signature.
- Produces: `auth/login` returns HTTP 429 after 5 failed attempts for the same email within 1 minute; `auth/register` returns 429 after 3 attempts per IP per minute.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Auth/LoginThrottleTest.php`:

```php
<?php

namespace Tests\Feature\Auth;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class LoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    private function makeOwner(string $email): User
    {
        $org = Organization::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Acme',
            'slug' => 'acme',
            'email' => $email,
            'subscription_plan' => 'free',
            'status' => 'active',
        ]);

        return User::create([
            'organization_id' => $org->id,
            'name' => 'Owner',
            'email' => $email,
            'password' => Hash::make('secret1234'),
            'role' => 'owner',
            'status' => 'active',
        ]);
    }

    public function test_login_is_throttled_after_five_failed_attempts(): void
    {
        $this->makeOwner('brute@x.test');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => 'brute@x.test',
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        // The sixth attempt is refused before the credentials are even checked,
        // so a correct password on attempt six must still be rejected.
        $this->postJson('/api/auth/login', [
            'email' => 'brute@x.test',
            'password' => 'secret1234',
        ])->assertStatus(429);
    }

    public function test_throttle_is_scoped_per_email_not_globally(): void
    {
        $this->makeOwner('victim@x.test');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => 'attacker@x.test',
                'password' => 'wrong-password',
            ]);
        }

        // A different account must not be locked out by someone else's failures.
        $this->postJson('/api/auth/login', [
            'email' => 'victim@x.test',
            'password' => 'secret1234',
        ])->assertOk();
    }

    public function test_successful_login_clears_the_attempt_counter(): void
    {
        $this->makeOwner('clears@x.test');

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => 'clears@x.test',
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        $this->postJson('/api/auth/login', [
            'email' => 'clears@x.test',
            'password' => 'secret1234',
        ])->assertOk();

        // Counter reset: three more failures must not trip the limiter.
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => 'clears@x.test',
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }
    }
}
```

- [ ] **Step 2: Run it and watch it fail for the right reason**

Run: `php artisan test tests/Feature/Auth/LoginThrottleTest.php`
Expected: `test_login_is_throttled_after_five_failed_attempts` FAILS with `Expected response status code [429] but received 200.` — proving there is no limiter today. The other two tests pass trivially (they will become meaningful once the limiter exists).

- [ ] **Step 3: Add the per-email limiter to `login`**

In `backend/app/Http/Controllers/Auth/AuthController.php` add the import:

```php
use Illuminate\Support\Facades\RateLimiter;
```

Replace the body of `login` with:

```php
    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Per-email limiter, deliberately separate from any per-IP throttle:
        // an attacker rotating IPs still cannot grind one account, and a
        // shared office IP cannot lock out everyone behind it.
        $key = 'login:'.strtolower($data['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => ['Too many login attempts. Please try again later.'],
            ])->status(429);
        }

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // A genuine sign-in clears the counter so a user who fat-fingers a
        // password twice is not one typo away from a lockout.
        RateLimiter::clear($key);

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user),
            'organization' => new OrganizationResource($user->organization->load('domains')),
        ]);
    }
```

- [ ] **Step 4: Throttle registration per IP**

In `backend/routes/api.php`, replace the two bare route declarations:

```php
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
```

with:

```php
    // Creating an organization is expensive (org + owner + domain + branch +
    // settings rows, plus a verification email). Three per minute per IP is
    // far above any human signup rate and well below a spam run.
    Route::post('register', [AuthController::class, 'register'])
        ->middleware('throttle:3,1');

    // The per-email lockout lives in AuthController::login; this per-IP cap
    // is the second layer, bounding an attacker spraying many accounts.
    Route::post('login', [AuthController::class, 'login'])
        ->middleware('throttle:20,1');
```

- [ ] **Step 5: Run the throttle tests**

Run: `php artisan test tests/Feature/Auth/LoginThrottleTest.php`
Expected: 3 passed.

- [ ] **Step 6: Confirm nothing else regressed**

Run: `php artisan test --compact`
Expected: `Tests: 314 passed`. Pay attention to `tests/Feature/Auth/RegisterTest.php` — if it posts to `/api/auth/register` more than 3 times in one test method, the new per-IP throttle will 429 it. If so, add `$this->travel(61)->seconds();` between the offending posts (same technique already used in `tests/Feature/Customer/AuthTest.php`), not a raised limit.

- [ ] **Step 7: Commit**

```bash
git add backend/routes/api.php backend/app/Http/Controllers/Auth/AuthController.php \
        backend/tests/Feature/Auth/LoginThrottleTest.php
git commit -m "fix: rate-limit login per email and registration per IP"
```

---

### Task 4: Give every new salon a default branch

`RegisterOrganization::execute()` creates the organization, owner, primary domain and settings row — but no branch. A branch is what carries address, map coordinates and opening hours, and `SlotGenerator::generate()` takes one. A freshly registered salon therefore has a public site with no address and a dashboard whose first job is an unexplained empty state. The Task 7 report from the customer-accounts branch already flagged this reachable state.

**Files:**
- Modify: `backend/app/Actions/Auth/RegisterOrganization.php`
- Test: `backend/tests/Feature/Auth/RegisterTest.php` (add one test)

**Interfaces:**
- Consumes: `RegisterOrganization::execute(array $data): array{organization: Organization, user: User}` — return shape unchanged, so `AuthController::register` needs no edit.
- Produces: exactly one `Branch` row per new organization, named after the salon, with `opening_hours_json` seeded Mon–Sat 09:00–18:00, Sunday closed. Task 5 reads that column for the public site.

- [ ] **Step 1: Write the failing test**

Append to `backend/tests/Feature/Auth/RegisterTest.php` (match the existing registration payload used by the tests already in that file):

```php
    public function test_registration_creates_a_default_branch_with_opening_hours(): void
    {
        $this->postJson('/api/auth/register', [
            'salon_name' => 'Beauty Queen',
            'name' => 'Rita Owner',
            'email' => 'rita@beautyqueen.test',
            'password' => 'secret1234',
            'password_confirmation' => 'secret1234',
        ])->assertStatus(201);

        $org = \App\Models\Organization::where('slug', 'beauty-queen')->firstOrFail();
        $branches = \App\Models\Branch::withoutGlobalScopes()
            ->where('organization_id', $org->id)
            ->get();

        $this->assertCount(1, $branches);
        $branch = $branches->first();
        $this->assertSame('Beauty Queen', $branch->name);
        // Monday open, Sunday closed — a salon can edit this, but never has
        // to before taking a first booking. Keys are the three-letter form
        // SlotGenerator indexes by (strtolower(format('D'))).
        $this->assertSame(['09:00', '18:00'], $branch->opening_hours_json['mon']);
        $this->assertNull($branch->opening_hours_json['sun']);
    }
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test tests/Feature/Auth/RegisterTest.php --filter test_registration_creates_a_default_branch`
Expected: FAIL with `Failed asserting that actual size 0 matches expected size 1.` — no branch is created today.

- [ ] **Step 3: Create the branch inside the registration transaction**

In `backend/app/Actions/Auth/RegisterOrganization.php` add the import:

```php
use App\Models\Branch;
```

Then, inside the `DB::transaction` closure, immediately after the `User::create([...])` block and before `Domain::create([...])`, insert:

```php
            // A salon is not bookable without a branch: it carries the
            // address, the map pin and the opening hours SlotGenerator
            // reads. Create one now so registration ends in a usable
            // state rather than an empty dashboard.
            Branch::create([
                'organization_id' => $organization->id,
                'name' => $data['salon_name'],
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'],
                'country' => $data['country'] ?? null,
                'opening_hours_json' => self::DEFAULT_OPENING_HOURS,
            ]);
```

And add the constant at the top of the class body, above `execute()`:

```php
    /**
     * Mon–Sat 09:00–18:00, closed Sunday. A conventional salon week the
     * owner can edit in Settings; null means closed that day, matching
     * SlotGenerator's reading of branches.opening_hours_json.
     *
     * Keys are the three-letter weekday form SlotGenerator indexes by
     * (`strtolower(Carbon::parse($date)->format('D'))`), the same shape
     * BranchFactory already produces.
     */
    protected const DEFAULT_OPENING_HOURS = [
        'mon' => ['09:00', '18:00'],
        'tue' => ['09:00', '18:00'],
        'wed' => ['09:00', '18:00'],
        'thu' => ['09:00', '18:00'],
        'fri' => ['09:00', '18:00'],
        'sat' => ['09:00', '18:00'],
        'sun' => null,
    ];
```

- [ ] **Step 4: Confirm the branch is tenant-scoped correctly**

`Branch` uses the `BelongsToOrganization` concern, whose `creating` hook fills `organization_id` from the bound tenant — but registration runs with **no tenant bound**, which is why `organization_id` is passed explicitly above. Verify the explicit value wins:

Run: `php artisan test tests/Feature/Auth/RegisterTest.php`
Expected: all PASS, including the new test. If `organization_id` comes back null, the concern is overwriting it — in that case bind the tenant around the `Branch::create` call rather than removing the explicit id.

- [ ] **Step 5: Confirm the plan limit still holds**

Free plan allows 1 branch. The default branch consumes it, so an owner who then adds a second must get a 422:

Run: `php artisan test tests/Feature/Crud/BranchCrudTest.php`
Expected: PASS. If a test there registers an org and then creates its "first" branch expecting success, update that test to reflect the new baseline of one existing branch — the product rule (free = 1 branch) does not change.

- [ ] **Step 6: Full suite**

Run: `php artisan test --compact`
Expected: zero failures.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Actions/Auth/RegisterOrganization.php backend/tests/Feature/Auth/RegisterTest.php
git commit -m "feat: create a default branch with opening hours at registration"
```

---

### Task 5: Serve real opening hours on the public site

`SiteController::hoursByBranch()` reads the `business_hours` table. Nothing in the entire codebase ever writes to that table — grep confirms the only references are the model, the migration and this reader. Meanwhile the hours that actually gate bookings live in `branches.opening_hours_json`, which is what `SlotGenerator` consults. Result: every salon's public page shows an empty hours block while its booking form honours hours the visitor cannot see. One source of truth; `opening_hours_json` is the one that already works.

**Files:**
- Modify: `backend/app/Http/Controllers/Public/SiteController.php` (`hoursByBranch()`, its call site, imports)
- Delete: `backend/app/Models/BusinessHour.php`
- Modify: `backend/app/Models/Branch.php` (remove the `businessHours()` relation)
- Create: `backend/database/migrations/2026_08_05_100000_drop_business_hours_table.php`
- Test: `backend/tests/Feature/Public/PublicSiteTest.php` (add one test)

**Interfaces:**
- Consumes: `branches.opening_hours_json` as written by Task 4 — a map of lowercase weekday name to `[open, close]` `H:i` pair, or `null` for closed.
- Produces: the `data.branches[].hours` array in the `GET /api/public/{org}/site` payload keeps its existing shape — `[{weekday:int, open_time:string|null, close_time:string|null, is_closed:bool}]`, Monday first — so `SalonSiteView.vue` needs no change.

- [ ] **Step 1: Write the failing test**

Append to `backend/tests/Feature/Public/PublicSiteTest.php`, reusing that file's existing scaffold helper:

```php
    public function test_site_returns_branch_opening_hours_monday_first(): void
    {
        $org = $this->scaffold(); // existing helper in this file

        \App\Models\Branch::withoutGlobalScopes()
            ->where('organization_id', $org->id)
            ->first()
            ->update([
                'opening_hours_json' => [
                    'mon' => ['09:00', '18:00'],
                    'sun' => null,
                ],
            ]);

        $hours = $this->getJson("/api/public/{$org->slug}/site")
            ->assertOk()
            ->json('data.branches.0.hours');

        // Monday opens the week, Sunday closes it — the way a salon prints it.
        $this->assertSame(1, $hours[0]['weekday']);
        $this->assertSame('09:00', $hours[0]['open_time']);
        $this->assertSame('18:00', $hours[0]['close_time']);
        $this->assertFalse($hours[0]['is_closed']);

        $sunday = collect($hours)->firstWhere('weekday', 0);
        $this->assertTrue($sunday['is_closed']);
        $this->assertNull($sunday['open_time']);
    }
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test tests/Feature/Public/PublicSiteTest.php --filter test_site_returns_branch_opening_hours`
Expected: FAIL with an undefined-index or empty-array error on `$hours[0]` — the site returns no hours at all today.

- [ ] **Step 3: Rewrite `hoursByBranch` to read the branch column**

In `backend/app/Http/Controllers/Public/SiteController.php`, delete the `use App\Models\BusinessHour;` import and replace the whole `hoursByBranch()` method with:

```php
    /**
     * Opening hours for every branch, derived from the same
     * branches.opening_hours_json that SlotGenerator gates bookings on —
     * so the page can never advertise hours the booking form refuses.
     *
     * Monday first the way a salon would print it; Sunday closes the week.
     *
     * @return Collection<int, array<int, array<string, mixed>>>
     */
    protected function hoursByBranch(Organization $organization): Collection
    {
        // Keys are the three-letter weekday form stored in
        // branches.opening_hours_json (what SlotGenerator indexes by);
        // values are Carbon's dayOfWeek numbers (Sunday = 0), which the
        // existing payload contract uses. Emitted Monday-first.
        $week = [
            'mon' => 1,
            'tue' => 2,
            'wed' => 3,
            'thu' => 4,
            'fri' => 5,
            'sat' => 6,
            'sun' => 0,
        ];

        return Branch::query()
            ->where('organization_id', $organization->id)
            ->get()
            ->mapWithKeys(function (Branch $branch) use ($week): array {
                $hours = $branch->opening_hours_json ?? [];

                $rows = [];
                foreach ($week as $name => $weekday) {
                    $pair = $hours[$name] ?? null;
                    $open = is_array($pair) ? ($pair[0] ?? null) : null;
                    $close = is_array($pair) ? ($pair[1] ?? null) : null;

                    $rows[] = [
                        'weekday' => $weekday,
                        'open_time' => $open,
                        'close_time' => $close,
                        // A day with no pair is a closed day, not a missing one.
                        'is_closed' => $open === null || $close === null,
                    ];
                }

                return [$branch->id => $rows];
            });
    }
```

- [ ] **Step 4: Update the call site**

Still in `SiteController.php`, find where `$hours` is assigned inside `branches()` and pass the organization through:

```php
        $hours = $this->hoursByBranch($organization);
```

and change the per-branch line from `$hours->get($branch->id, collect())->values()->all()` to:

```php
            'hours' => $hours->get($branch->id, []),
```

(The method now returns plain arrays, not collections.)

- [ ] **Step 5: Run the test**

Run: `php artisan test tests/Feature/Public/PublicSiteTest.php`
Expected: all PASS.

- [ ] **Step 6: Remove the dead table, model and relation**

Delete the model:

```bash
rm backend/app/Models/BusinessHour.php
```

In `backend/app/Models/Branch.php`, delete the `businessHours()` relation method (around line 49) and its `BusinessHour` import.

Create `backend/database/migrations/2026_08_05_100000_drop_business_hours_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * business_hours was never written to by any code path — opening hours
     * live on branches.opening_hours_json, which is what both the booking
     * engine and the public site read. Drop the empty table rather than
     * leave a second, always-stale source of truth.
     */
    public function up(): void
    {
        Schema::dropIfExists('business_hours');
    }

    public function down(): void
    {
        Schema::create('business_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->time('open_time')->nullable();
            $table->time('close_time')->nullable();
            $table->boolean('is_closed')->default(false);
        });
    }
};
```

- [ ] **Step 7: Prove nothing else referenced it**

Run: `grep -rn "BusinessHour\|business_hours" backend/app backend/tests backend/database/seeders`
Expected: only the new drop migration and the original create migration match. Any other hit must be resolved before continuing.

- [ ] **Step 8: Full suite**

Run: `php artisan test --compact`
Expected: zero failures.

- [ ] **Step 9: Commit**

```bash
git add backend/app/Http/Controllers/Public/SiteController.php backend/app/Models/Branch.php \
        backend/database/migrations/2026_08_05_100000_drop_business_hours_table.php \
        backend/tests/Feature/Public/PublicSiteTest.php
git rm backend/app/Models/BusinessHour.php
git commit -m "fix: serve public opening hours from branches.opening_hours_json, drop dead business_hours table"
```

---

### Task 6: Terms, Privacy and Refund pages

The marketing footer links to Features, Pricing, FAQ, Contact, login and register — no legal links at all. SSLCommerz payments are live, which makes a refund policy a payment-processor requirement, not a nicety. Three static views plus footer links.

**Files:**
- Create: `frontend/src/views/legal/TermsView.vue`, `frontend/src/views/legal/PrivacyView.vue`, `frontend/src/views/legal/RefundView.vue`
- Create: `frontend/src/components/legal/LegalPage.vue` (shared shell: nav, title, prose container, footer)
- Modify: `frontend/src/router/index.js`
- Modify: `frontend/src/components/marketing/MarketingFooter.vue`

**Interfaces:**
- Consumes: `MarketingNav.vue` and `MarketingFooter.vue` for the shell; the `paper`/`ink`/`brand-*` tokens and `font-display`/`font-body` from `main.css`.
- Produces: routes named `terms`, `privacy`, `refund` at `/terms`, `/privacy`, `/refund` — public, no auth meta. Task 7's SPA fallback must serve them.

- [ ] **Step 1: Build the shared legal shell**

Create `frontend/src/components/legal/LegalPage.vue`. It takes a `title` and `updated` prop and renders `<slot />` inside a readable prose column, wrapped in the same nav and footer the marketing pages use:

```vue
<script setup>
import MarketingNav from '@/components/marketing/MarketingNav.vue'
import MarketingFooter from '@/components/marketing/MarketingFooter.vue'

defineProps({
  title: { type: String, required: true },
  updated: { type: String, required: true },
})
</script>

<template>
  <div class="min-h-screen bg-paper text-ink">
    <MarketingNav />
    <main class="mx-auto max-w-3xl px-6 py-16">
      <h1 class="font-display text-4xl text-ink">{{ title }}</h1>
      <p class="mt-2 text-sm text-ink/60">Last updated {{ updated }}</p>
      <div class="legal-prose mt-10 space-y-6 text-ink/80">
        <slot />
      </div>
    </main>
    <MarketingFooter />
  </div>
</template>

<style scoped>
.legal-prose :deep(h2) {
  font-family: var(--font-display);
  font-size: 1.375rem;
  color: var(--color-ink);
  margin-top: 2rem;
}
.legal-prose :deep(p),
.legal-prose :deep(li) {
  line-height: 1.7;
}
.legal-prose :deep(ul) {
  list-style: disc;
  padding-left: 1.25rem;
}
</style>
```

- [ ] **Step 2: Write the three legal views**

Each view is `LegalPage` plus content. Create `frontend/src/views/legal/TermsView.vue`:

```vue
<script setup>
import LegalPage from '@/components/legal/LegalPage.vue'
</script>

<template>
  <LegalPage title="Terms of Service" updated="5 August 2026">
    <h2>1. Who we are</h2>
    <p>
      SalonHub is a booking and salon-management service. These terms govern your use of
      the SalonHub dashboard, the booking website we host for your salon, and the
      SalonHub marketing site.
    </p>

    <h2>2. Your account</h2>
    <p>
      You must give accurate registration details and keep your password secure. You are
      responsible for everything done under your account, including actions by staff
      members you invite.
    </p>

    <h2>3. Acceptable use</h2>
    <ul>
      <li>Do not use SalonHub to send unsolicited marketing to your customers.</li>
      <li>Do not upload content you do not hold the rights to.</li>
      <li>Do not attempt to access another salon's data.</li>
    </ul>

    <h2>4. Your customers' data</h2>
    <p>
      Customer records you create in SalonHub belong to you. We process them on your
      behalf as described in our Privacy Policy, and we do not sell them or use them to
      market to your customers.
    </p>

    <h2>5. Plans and payment</h2>
    <p>
      The Free plan is offered at no cost and is limited to one branch and ten staff
      members. Deposits your customers pay online are collected through your own payment
      gateway credentials and settle to you, not to SalonHub.
    </p>

    <h2>6. Availability</h2>
    <p>
      We aim to keep SalonHub available continuously but do not guarantee uninterrupted
      service. We may suspend access for maintenance or where an account breaches
      these terms.
    </p>

    <h2>7. Ending your account</h2>
    <p>
      You may close your account at any time by contacting us. On closure we delete your
      organization's data within 30 days, except where we must retain records by law.
    </p>

    <h2>8. Changes</h2>
    <p>
      We may update these terms. Material changes will be announced in the dashboard at
      least 14 days before they take effect.
    </p>

    <h2>9. Contact</h2>
    <p>Questions about these terms: <a href="mailto:hello@salonhub.com">hello@salonhub.com</a>.</p>
  </LegalPage>
</template>
```

Create `frontend/src/views/legal/PrivacyView.vue` with the same structure and these sections: **What we collect** (salon account details; customer name, phone, email and booking notes entered by the salon; payment transaction references, never card numbers); **Why we collect it** (operate the booking service, send booking confirmations and reminders, support); **Who we share it with** (the salon that owns the record; email delivery provider; SMS/WhatsApp provider when reminders are enabled; payment gateway when a deposit is taken — no one else, and never sold); **How long we keep it** (for the life of the salon's account, deleted within 30 days of closure); **Your rights** (access, correction, deletion — contact the salon that holds your booking, or `hello@salonhub.com`); **Cookies** (a single first-party token in local storage to keep you signed in; no advertising or third-party tracking cookies); **Contact**.

Create `frontend/src/views/legal/RefundView.vue` with sections: **Deposits** (a deposit is taken by the salon through the salon's own gateway; SalonHub never holds the funds); **Cancelling a booking** (a customer may cancel a changeable booking from the manage-booking link or their account; whether the deposit is refunded is the salon's policy, shown at booking time); **How refunds are issued** (the salon issues the refund from the dashboard; funds return to the original payment method, typically within 5–10 business days depending on the bank); **Disputes** (contact the salon first; if unresolved, `hello@salonhub.com`); **SalonHub subscription** (the Free plan carries no charge, so there is nothing to refund).

- [ ] **Step 3: Register the routes**

In `frontend/src/router/index.js`, add three public routes as top-level siblings of the `landing` route, declared **before** the `DashboardLayout` record so they resolve as leaf paths:

```js
    {
      path: '/terms',
      name: 'terms',
      component: () => import('@/views/legal/TermsView.vue'),
    },
    {
      path: '/privacy',
      name: 'privacy',
      component: () => import('@/views/legal/PrivacyView.vue'),
    },
    {
      path: '/refund',
      name: 'refund',
      component: () => import('@/views/legal/RefundView.vue'),
    },
```

Do not add them to the authed-redirect list in `beforeEach` — a signed-in owner must still be able to read the terms.

- [ ] **Step 4: Link them from the footer**

In `frontend/src/components/marketing/MarketingFooter.vue`, add a legal column alongside the existing Product and Account columns:

```vue
          <li>
            <RouterLink to="/terms" class="text-paper/70 transition-colors hover:text-paper">Terms of Service</RouterLink>
          </li>
          <li>
            <RouterLink to="/privacy" class="text-paper/70 transition-colors hover:text-paper">Privacy Policy</RouterLink>
          </li>
          <li>
            <RouterLink to="/refund" class="text-paper/70 transition-colors hover:text-paper">Refund Policy</RouterLink>
          </li>
```

with a `<h3>Legal</h3>` heading matching the styling of the sibling column headings.

- [ ] **Step 5: Build and click through**

Run: `cd frontend && npm run build`
Expected: clean build, `TermsView`, `PrivacyView`, `RefundView` chunks emitted.

Run `npm run dev` and visit `/`, then click each footer legal link. Each must render the nav, the content and the footer, and the footer links on the legal page itself must still work (no dead loop).

- [ ] **Step 6: Commit**

```bash
git add frontend/src/components/legal frontend/src/views/legal \
        frontend/src/components/marketing/MarketingFooter.vue frontend/src/router/index.js
git commit -m "feat: terms, privacy and refund policy pages linked from the footer"
```

---

### Task 7: Serve the SPA for deep links

`routes/web.php` still returns Laravel's stock `welcome` view at `/`. In production the Vue build is a separate static bundle, and any deep link — `/salon/beauty-queen`, `/account`, `/terms`, `/dashboard/appointments` — is a client-side route with no server route behind it. Without a fallback, a refresh on any of those returns 404. This task makes the fallback explicit in both nginx (Task 10 consumes it) and Laravel, so either deployment topology works.

**Files:**
- Modify: `backend/routes/web.php`
- Create: `backend/resources/views/app.blade.php`
- Test: `backend/tests/Feature/SpaFallbackTest.php` (create)

**Interfaces:**
- Consumes: the Vite build manifest at `frontend/dist` — copied to `backend/public/app` by the deploy script in Task 10.
- Produces: any non-`/api`, non-`/storage` GET returns the SPA shell with HTTP 200. `/api/*` 404s stay JSON 404s.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/SpaFallbackTest.php`:

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpaFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_deep_link_returns_the_spa_shell(): void
    {
        $this->get('/salon/beauty-queen')
            ->assertOk()
            ->assertSee('id="app"', false);
    }

    public function test_unknown_api_route_still_returns_json_404(): void
    {
        $this->getJson('/api/does-not-exist')->assertStatus(404);
    }

    public function test_health_check_is_not_swallowed_by_the_fallback(): void
    {
        $this->get('/up')->assertOk();
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test tests/Feature/SpaFallbackTest.php`
Expected: `test_deep_link_returns_the_spa_shell` FAILS with a 404 — there is no fallback route.

- [ ] **Step 3: Create the SPA shell view**

Create `backend/resources/views/app.blade.php`:

```blade
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SalonHub</title>
    {{-- The Vue build is copied to public/app by the deploy script. The
         manifest is read at request time so a redeploy needs no cache clear. --}}
    @php
        $manifest = public_path('app/.vite/manifest.json');
        $entry = file_exists($manifest)
            ? json_decode(file_get_contents($manifest), true)['src/main.js'] ?? null
            : null;
    @endphp
    @if ($entry)
        @foreach ($entry['css'] ?? [] as $css)
            <link rel="stylesheet" href="/app/{{ $css }}">
        @endforeach
        <script type="module" src="/app/{{ $entry['file'] }}"></script>
    @endif
</head>
<body>
    <div id="app"></div>
</body>
</html>
```

- [ ] **Step 4: Replace the welcome route with a fallback**

Replace the whole of `backend/routes/web.php` with:

```php
<?php

use Illuminate\Support\Facades\Route;

/*
 * Everything that is not an API call, an uploaded file or the health check
 * is a client-side route: hand back the SPA shell and let vue-router decide.
 * Registered as a fallback so it never shadows a real route.
 */
Route::fallback(function () {
    return view('app');
});
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test tests/Feature/SpaFallbackTest.php`
Expected: 3 passed. Note that `Route::fallback` in `web.php` does not capture `/api/*` — those are registered in the api group and Laravel's api routes 404 as JSON before the web fallback is reached. If `test_unknown_api_route_still_returns_json_404` fails, the fallback is too greedy; scope it with `->where('any', '^(?!api|storage|up).*$')` on an explicit `Route::get('/{any}', ...)` instead.

- [ ] **Step 6: Full suite**

Run: `php artisan test --compact`
Expected: zero failures. Watch for any existing test asserting the Laravel welcome page — delete it if present, it tested scaffolding.

- [ ] **Step 7: Commit**

```bash
git add backend/routes/web.php backend/resources/views/app.blade.php \
        backend/tests/Feature/SpaFallbackTest.php
git commit -m "feat: serve the SPA shell for client-side deep links"
```

---

### Task 8: Make CORS environment-driven with subdomain support

`config/cors.php` hardcodes `['http://localhost:5173', 'http://127.0.0.1:5173']`. In production the dashboard runs on `app.salonhub.com` and each salon's booking site on `<slug>.salonhub.com` — every one of those origins is currently rejected. Origins must come from env, and the wildcard subdomain must be matched by pattern.

**Files:**
- Modify: `backend/config/cors.php`
- Modify: `backend/.env.example`
- Test: `backend/tests/Feature/CorsConfigTest.php` (create)

**Interfaces:**
- Consumes: env vars `CORS_ALLOWED_ORIGINS` (comma-separated absolute origins) and `APP_DOMAIN` (bare apex, e.g. `salonhub.com`).
- Produces: `config('cors.allowed_origins')` as an array; `config('cors.allowed_origins_patterns')` containing one regex matching `https://<anything>.<APP_DOMAIN>`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/CorsConfigTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

class CorsConfigTest extends TestCase
{
    public function test_allowed_origins_come_from_env_as_a_list(): void
    {
        config(['cors.allowed_origins' => null]); // ensure we read the real config
        $origins = config('cors.allowed_origins');

        $this->assertIsArray($origins);
        // Dev origins remain the default so `npm run dev` keeps working.
        $this->assertContains('http://localhost:5173', $origins);
    }

    public function test_a_salon_subdomain_is_matched_by_pattern(): void
    {
        $patterns = config('cors.allowed_origins_patterns');

        $this->assertNotEmpty($patterns, 'No subdomain pattern configured.');

        $matched = collect($patterns)->contains(
            fn (string $pattern) => preg_match($pattern, 'https://beauty-queen.salonhub.com') === 1
        );

        $this->assertTrue($matched, 'A salon subdomain origin is not allowed by any pattern.');
    }

    public function test_a_lookalike_domain_is_not_matched(): void
    {
        $patterns = config('cors.allowed_origins_patterns');

        $matched = collect($patterns)->contains(
            fn (string $pattern) => preg_match($pattern, 'https://salonhub.com.evil.test') === 1
        );

        $this->assertFalse($matched, 'A lookalike domain must not be allowed.');
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test tests/Feature/CorsConfigTest.php`
Expected: `test_a_salon_subdomain_is_matched_by_pattern` FAILS on the empty-patterns assertion.

- [ ] **Step 3: Rewrite the config**

Replace the `allowed_origins` and `allowed_origins_patterns` entries in `backend/config/cors.php`:

```php
    // Absolute origins, comma-separated in CORS_ALLOWED_ORIGINS. The Vite dev
    // server stays in the default so a fresh checkout works with no .env edit.
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173,http://127.0.0.1:5173'))
    ))),

    // Every salon gets <slug>.APP_DOMAIN, so the subdomain space is matched by
    // pattern rather than enumerated. Anchored at both ends and the dot before
    // the apex is escaped, so `salonhub.com.evil.test` cannot match.
    'allowed_origins_patterns' => [
        '#^https://[a-z0-9-]+\.'.preg_quote((string) env('APP_DOMAIN', 'salonhub.com'), '#').'$#i',
    ],
```

- [ ] **Step 4: Document the env vars**

Append to `backend/.env.example`, under the `APP_URL` block:

```
# Apex domain salons get a subdomain under: <slug>.APP_DOMAIN.
APP_DOMAIN=salonhub.com

# Absolute origins allowed to call the API, comma-separated. Salon subdomains
# are matched by pattern from APP_DOMAIN and do not need listing here.
CORS_ALLOWED_ORIGINS=http://localhost:5173,http://127.0.0.1:5173
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test tests/Feature/CorsConfigTest.php`
Expected: 3 passed.

- [ ] **Step 6: Verify a real preflight**

Run: `php artisan serve` then:

```bash
curl -i -X OPTIONS http://127.0.0.1:8000/api/hello \
  -H "Origin: http://localhost:5173" \
  -H "Access-Control-Request-Method: GET"
```

Expected: `Access-Control-Allow-Origin: http://localhost:5173` in the response headers.

- [ ] **Step 7: Commit**

```bash
git add backend/config/cors.php backend/.env.example backend/tests/Feature/CorsConfigTest.php
git commit -m "feat: env-driven CORS origins with salon-subdomain pattern"
```

---

### Task 9: A production environment template

`.env.example` is a development file: `DB_CONNECTION=sqlite`, `MAIL_MAILER=log`, `APP_DEBUG=true`. Deploying from it produces an app with a stack-trace-leaking debug page, a file database and silently discarded email. Keep `.env.example` as the local-dev template it is; add a separate, annotated production template.

**Files:**
- Create: `backend/docs/deploy/env.production.example`
- Modify: `backend/.env.example` (comment clarifying it is local-only)

**Interfaces:**
- Consumes: `APP_DOMAIN` and `CORS_ALLOWED_ORIGINS` introduced in Task 8; `CONTACT_EMAIL` already present.
- Produces: the file Task 10's runbook tells the operator to copy to `.env` on the VPS.

- [ ] **Step 1: Write the production template**

Create `backend/docs/deploy/env.production.example`:

```
# SalonHub — production environment template.
# Copy to /var/www/salonhub/backend/.env on the server, fill every value
# marked CHANGE-ME, then run: php artisan key:generate && php artisan config:cache

APP_NAME=SalonHub
APP_ENV=production
APP_KEY=                      # CHANGE-ME: php artisan key:generate writes this
APP_DEBUG=false               # never true in production: leaks stack traces and env
APP_URL=https://app.salonhub.com
APP_DOMAIN=salonhub.com
FRONTEND_URL=https://app.salonhub.com

APP_LOCALE=en
APP_FALLBACK_LOCALE=en

LOG_CHANNEL=stack
LOG_STACK=daily               # daily, not single: single grows unbounded on a VPS
LOG_LEVEL=warning             # debug in production writes customer data to disk

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=salonhub
DB_USERNAME=salonhub
DB_PASSWORD=                  # CHANGE-ME

# Redis carries queue and cache. Requires the phpredis extension.
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

QUEUE_CONNECTION=redis        # every Mailable is ShouldQueue: without a worker, no mail sends
CACHE_STORE=redis
SESSION_DRIVER=redis
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=true

FILESYSTEM_DISK=public        # logos, covers and gallery images; needs `php artisan storage:link`

# Transactional email. A real SMTP relay — MAIL_MAILER=log discards everything.
MAIL_MAILER=smtp
MAIL_HOST=                    # CHANGE-ME
MAIL_PORT=587
MAIL_USERNAME=                # CHANGE-ME
MAIL_PASSWORD=                # CHANGE-ME
MAIL_SCHEME=tls
MAIL_FROM_ADDRESS="bookings@salonhub.com"
MAIL_FROM_NAME="SalonHub"

# Where the marketing contact form delivers.
CONTACT_EMAIL="hello@salonhub.com"

# Origins allowed to call the API. Salon subdomains match by pattern from
# APP_DOMAIN and need no entry here.
CORS_ALLOWED_ORIGINS=https://app.salonhub.com,https://salonhub.com

# Platform Twilio account for reminders. A salon may connect its own in
# Settings; these carry everyone else. Empty = reminders log instead of send.
TWILIO_ACCOUNT_SID=
TWILIO_AUTH_TOKEN=
TWILIO_FROM=
TWILIO_WHATSAPP_FROM=
TWILIO_MESSAGING_SERVICE_SID=

# Error monitoring (Task 13). Empty = disabled.
SENTRY_LARAVEL_DSN=
SENTRY_TRACES_SAMPLE_RATE=0.1
```

- [ ] **Step 2: Mark `.env.example` as local-only**

Add as the first line of `backend/.env.example`:

```
# Local development template. For production, copy docs/deploy/env.production.example.
```

- [ ] **Step 3: Verify the template is complete**

Run: `grep -oE "env\('[A-Z_]+'" -r backend/config | grep -oE "[A-Z_]+" | sort -u > /tmp/used.txt`
Then check each var in `/tmp/used.txt` that has no default in `config/` appears in the production template. Any missing one is a boot-time failure on the server, so add it.

- [ ] **Step 4: Commit**

```bash
git add backend/docs/deploy/env.production.example backend/.env.example
git commit -m "docs: production environment template"
```

---

### Task 10: Deployment runbook — nginx, queue worker, scheduler

Nothing about running this app in production exists. Two of these are not optional extras: **every Mailable implements `ShouldQueue`**, so with no worker running not one booking confirmation is ever sent; and `bootstrap/app.php` schedules `reminders:send` hourly plus `bookings:release-abandoned` every fifteen minutes, which never fire without cron.

**Files:**
- Create: `backend/docs/deploy/README.md` (the runbook)
- Create: `backend/docs/deploy/nginx-app.conf`, `backend/docs/deploy/nginx-salon.conf`
- Create: `backend/docs/deploy/salonhub-worker.conf` (supervisor)
- Create: `backend/docs/deploy/deploy.sh`

**Interfaces:**
- Consumes: `docs/deploy/env.production.example` (Task 9), the SPA fallback route (Task 7), `APP_DOMAIN` (Task 8).
- Produces: the operator-facing procedure. Task 12's wildcard vhost extends `nginx-salon.conf`; Task 14's backup cron is added to the same crontab this task creates.

- [ ] **Step 1: Write the nginx vhost for the dashboard and API**

Create `backend/docs/deploy/nginx-app.conf`:

```nginx
# app.salonhub.com — dashboard SPA + API, one origin so the SPA needs no CORS.
server {
    listen 443 ssl http2;
    server_name app.salonhub.com salonhub.com www.salonhub.com;

    root /var/www/salonhub/backend/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/salonhub.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/salonhub.com/privkey.pem;

    client_max_body_size 12M;   # gallery and cover uploads

    add_header X-Frame-Options SAMEORIGIN always;
    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;

    # Hashed Vite assets never change under a given name.
    location /app/assets/ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    # Uploaded images, served from the storage symlink.
    location /storage/ {
        expires 7d;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* { deny all; }
}

server {
    listen 80;
    server_name app.salonhub.com salonhub.com www.salonhub.com;
    return 301 https://$host$request_uri;
}
```

- [ ] **Step 2: Write the wildcard vhost for salon subdomains**

Create `backend/docs/deploy/nginx-salon.conf`:

```nginx
# *.salonhub.com — one vhost serves every salon's booking site. The slug is
# read from the Host header by the app (ResolvePublicTenant) and by the SPA
# (src/lib/tenantHost.js), so no per-salon nginx config is ever generated.
#
# Requires a wildcard DNS A record (*.salonhub.com -> this VPS) and a wildcard
# certificate, which needs the DNS-01 challenge:
#   certbot certonly --dns-cloudflare -d salonhub.com -d '*.salonhub.com'
server {
    listen 443 ssl http2;
    server_name ~^(?<slug>[a-z0-9-]+)\.salonhub\.com$;

    root /var/www/salonhub/backend/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/salonhub.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/salonhub.com/privkey.pem;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* { deny all; }
}

server {
    listen 80;
    server_name ~^[a-z0-9-]+\.salonhub\.com$;
    return 301 https://$host$request_uri;
}
```

- [ ] **Step 3: Write the supervisor program for the queue worker**

Create `backend/docs/deploy/salonhub-worker.conf`:

```ini
; Queue worker. NOT OPTIONAL: every Mailable in this app implements
; ShouldQueue, so with no worker running, zero booking confirmations,
; cancellations, reschedules or reminders are ever delivered.
;
; Install to /etc/supervisor/conf.d/salonhub-worker.conf then:
;   supervisorctl reread && supervisorctl update && supervisorctl start salonhub-worker:*
[program:salonhub-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/salonhub/backend/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/salonhub/worker.log
stopwaitsecs=3600
```

- [ ] **Step 4: Write the deploy script**

Create `backend/docs/deploy/deploy.sh`:

```bash
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
```

Make it executable: `chmod +x backend/docs/deploy/deploy.sh`

- [ ] **Step 5: Write the runbook that ties it together**

Create `backend/docs/deploy/README.md` covering, in order: server prerequisites (Ubuntu 24.04, PHP 8.4 + fpm/mbstring/xml/curl/zip/mysql/redis extensions, MySQL 8, Redis, nginx, supervisor, Node 22, composer, certbot with the DNS plugin); DNS (`A app`, `A @`, `A *` all to the VPS IP, proxied through Cloudflare); certificates (wildcard via DNS-01, the exact certbot command from `nginx-salon.conf`); first deploy (clone, copy `env.production.example` to `.env`, fill the CHANGE-ME values, `php artisan key:generate`, `php artisan migrate --force`, run `deploy.sh`); install the two vhosts and the supervisor program; **the cron line** — install with `crontab -e -u www-data`:

```
* * * * * cd /var/www/salonhub/backend && php artisan schedule:run >> /dev/null 2>&1
```

with the note that without this line `reminders:send` (hourly) and `bookings:release-abandoned` (every 15 min) never run, so reminders are never sent and unpaid held slots are never released; and a **verification checklist**: `curl -sf https://app.salonhub.com/up` returns 200, `supervisorctl status salonhub-worker:*` shows RUNNING, `php artisan queue:work --once` drains a test job, `php artisan schedule:list` shows both commands, a test registration receives its verification email, and a test booking receives its confirmation email.

- [ ] **Step 6: Verify the scripts are syntactically sound**

Run: `bash -n backend/docs/deploy/deploy.sh`
Expected: no output (valid syntax).

Run: `nginx -t -c /dev/null` is not meaningful locally — instead confirm the vhosts by eye against the runbook, and note in the runbook that `nginx -t` must pass on the server before `systemctl reload nginx`.

- [ ] **Step 7: Commit**

```bash
git add backend/docs/deploy
git commit -m "docs: deployment runbook with nginx, supervisor queue worker and scheduler cron"
```

---

### Task 11: Continuous integration

No `.github` directory exists. Task 1's fix is the second date-dependent breakage this repo has shipped; CI is what catches the third before a human does.

**Files:**
- Create: `.github/workflows/ci.yml`

**Interfaces:**
- Consumes: `backend/composer.json`, `frontend/package.json`, `backend/phpunit.xml` (sqlite in-memory).
- Produces: a required status check on every push and pull request against `main`.

- [ ] **Step 1: Write the workflow**

Create `.github/workflows/ci.yml`:

```yaml
name: CI

on:
  push:
    branches: [main]
  pull_request:

jobs:
  backend:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          extensions: mbstring, xml, curl, zip, sqlite3, pdo_sqlite
          coverage: none

      - name: Cache composer packages
        uses: actions/cache@v4
        with:
          path: ~/.composer/cache
          key: composer-${{ hashFiles('backend/composer.lock') }}

      - name: Install dependencies
        working-directory: backend
        run: composer install --prefer-dist --no-interaction --no-progress

      - name: Prepare environment
        working-directory: backend
        run: |
          cp .env.example .env
          php artisan key:generate

      - name: Run the test suite
        working-directory: backend
        run: php artisan test

      - name: Check formatting
        working-directory: backend
        run: ./vendor/bin/pint --test

  frontend:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - uses: actions/setup-node@v4
        with:
          node-version: '22'
          cache: npm
          cache-dependency-path: frontend/package-lock.json

      - name: Install dependencies
        working-directory: frontend
        run: npm ci

      - name: Build
        working-directory: frontend
        run: npm run build
```

- [ ] **Step 2: Verify both jobs would pass locally before pushing**

Run: `cd backend && composer install && php artisan test && ./vendor/bin/pint --test`
Expected: tests green, pint reports no style issues. If pint wants to reformat files unrelated to this plan, run `./vendor/bin/pint` once, review the diff, and commit it as a separate `style:` commit before adding CI — do not let the first CI run be red.

Run: `cd frontend && npm ci && npm run build`
Expected: clean build.

- [ ] **Step 3: Commit**

```bash
git add .github/workflows/ci.yml
git commit -m "ci: run backend tests, pint and the frontend build on every push"
```

- [ ] **Step 4: Add the vitest job once Task 15 lands**

Note in the workflow (a comment above the `frontend` job) that a `npm run test:unit` step is added by Task 15. Do not add the step now — it would fail with no test runner installed.

---

### Task 12: Make the salon subdomain real

Registration writes a `Domain` row for `<slug>.salonhub.com` with `is_verified = false, ssl_enabled = false`, and `SubdomainBanner.vue` advertises that URL to every owner on their dashboard — but nothing serves it. The SPA only knows `/salon/:slug`, and `ResolvePublicTenant` only falls back to the Host header when there is no `{org}` route parameter. This is the product's headline promise from CLAUDE.md ("Receive a branded subdomain automatically"), so it either works or the banner comes down.

**Files:**
- Create: `frontend/src/lib/tenantHost.js`
- Modify: `frontend/src/router/index.js`
- Modify: `frontend/src/components/SubdomainBanner.vue`
- Modify: `backend/app/Actions/Auth/RegisterOrganization.php` (domain flags)
- Test: `backend/tests/Feature/Public/SubdomainResolutionTest.php` (create)

**Interfaces:**
- Consumes: `nginx-salon.conf` and the wildcard certificate from Task 10; `APP_DOMAIN` from Task 8; `Domain` model with `organization_id`, `domain`, `is_primary`.
- Produces: `resolveSlugFromHost(host: string, appDomain: string): string | null` exported from `src/lib/tenantHost.js`; when it returns a slug, the router serves `SalonSiteView` at `/` for that host.

- [ ] **Step 1: Write the failing backend test**

Create `backend/tests/Feature/Public/SubdomainResolutionTest.php`:

```php
<?php

namespace Tests\Feature\Public;

use App\Models\Domain;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubdomainResolutionTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrg(string $slug): Organization
    {
        $org = Organization::create([
            'uuid' => (string) Str::uuid(),
            'name' => ucfirst($slug),
            'slug' => $slug,
            'email' => "owner@{$slug}.test",
            'subscription_plan' => 'free',
            'status' => 'active',
        ]);

        Domain::create([
            'organization_id' => $org->id,
            'domain' => "{$slug}.salonhub.com",
            'is_primary' => true,
            'is_verified' => true,
            'ssl_enabled' => true,
        ]);

        return $org;
    }

    public function test_site_resolves_from_the_host_header_without_a_slug_in_the_path(): void
    {
        $this->makeOrg('beauty-queen');

        $this->withHeader('Host', 'beauty-queen.salonhub.com')
            ->getJson('/api/public/site')
            ->assertOk()
            ->assertJsonPath('data.slug', 'beauty-queen');
    }

    public function test_an_unknown_host_is_a_404_not_another_salon(): void
    {
        $this->makeOrg('beauty-queen');

        $this->withHeader('Host', 'nobody.salonhub.com')
            ->getJson('/api/public/site')
            ->assertStatus(404);
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test tests/Feature/Public/SubdomainResolutionTest.php`
Expected: 404 on the first test — `/api/public/site` (no `{org}` segment) is not a registered route.

- [ ] **Step 3: Register host-resolved public routes**

In `backend/routes/api.php`, add a second public group **above** the existing `public/{org}` group. It reuses `public.tenant`, whose Host-header branch already handles the lookup when no `{org}` parameter is present:

```php
// Host-resolved public site: <slug>.salonhub.com serves the same payloads as
// /api/public/{org}/*, with the tenant read from the Host header instead of
// the path. Declared before the {org} group so `site` is never mistaken for
// an organization slug.
Route::prefix('public')->middleware('public.tenant')->group(function () {
    Route::get('site', SiteController::class);
    Route::get('services', [BookingController::class, 'services']);
    Route::get('services/{service}/staff', [BookingController::class, 'staffForService']);
    Route::get('slots', [BookingController::class, 'slots']);
    Route::post('book', [BookingController::class, 'book']);
});
```

- [ ] **Step 4: Verify the tenant resolver rejects unknown hosts**

`ResolvePublicTenant` already `abort(404)`s when neither branch finds an organization. Confirm with:

Run: `php artisan test tests/Feature/Public/SubdomainResolutionTest.php`
Expected: 2 passed.

- [ ] **Step 5: Mark the primary domain verified at registration**

The subdomain is served by a wildcard vhost and a wildcard certificate the moment the row exists — nothing needs verifying. In `backend/app/Actions/Auth/RegisterOrganization.php`, change the `Domain::create` call to reflect reality:

```php
            Domain::create([
                'organization_id' => $organization->id,
                'domain' => $slug.'.'.config('app.domain'),
                // Served immediately by the wildcard vhost and wildcard cert:
                // there is nothing to verify for a subdomain we control. A
                // future custom domain (v1.2) is the case that starts false.
                'is_primary' => true,
                'is_verified' => true,
                'ssl_enabled' => true,
            ]);
```

Add to `backend/config/app.php`, in the top-level array:

```php
    /*
    |--------------------------------------------------------------------------
    | Apex Domain
    |--------------------------------------------------------------------------
    |
    | Salons get a subdomain under this apex. Used when minting the primary
    | domain row at registration and when the SPA maps a Host header back
    | to a salon slug.
    |
    */

    'domain' => env('APP_DOMAIN', 'salonhub.com'),
```

- [ ] **Step 6: Add the frontend host resolver**

Create `frontend/src/lib/tenantHost.js`:

```js
// The apex salons get a subdomain under. Mirrors backend config('app.domain').
const APP_DOMAIN = import.meta.env.VITE_APP_DOMAIN || 'salonhub.com'

// Hosts that are the product itself, never a salon.
const RESERVED = new Set(['app', 'www', 'api', 'admin', 'mail', 'static'])

/**
 * Map a Host header to a salon slug, or null when the host is the marketing
 * site, the dashboard, or anything outside our apex.
 *
 * Exact suffix match only: `salonhub.com.evil.test` must not resolve.
 */
export function resolveSlugFromHost(host = window.location.hostname, appDomain = APP_DOMAIN) {
  const bare = host.split(':')[0].toLowerCase()

  if (bare === appDomain) return null
  if (!bare.endsWith(`.${appDomain}`)) return null

  const label = bare.slice(0, -(appDomain.length + 1))

  // Only a single label is a salon: `a.b.salonhub.com` is not.
  if (label.includes('.')) return null
  if (RESERVED.has(label)) return null
  if (!/^[a-z0-9-]+$/.test(label)) return null

  return label
}
```

- [ ] **Step 7: Serve the salon site at `/` on a salon host**

In `frontend/src/router/index.js`, import the resolver and branch the root route. Replace the `landing` route with:

```js
    {
      // On a salon subdomain, `/` is that salon's shopfront. On the apex it is
      // the marketing site. Resolved once at module load: the host cannot
      // change without a full page load.
      path: '/',
      name: 'landing',
      component: () =>
        resolveSlugFromHost()
          ? import('@/views/SalonSiteView.vue')
          : import('@/views/LandingView.vue'),
    },
```

`SalonSiteView.vue` currently reads its slug from `route.params.slug`. Make it fall back to the host:

```js
const slug = route.params.slug || resolveSlugFromHost()
```

and, when `route.params.slug` is absent, have its API calls target the host-resolved endpoints (`/public/site` rather than `/public/${slug}/site`). Add a small helper in `tenantHost.js`:

```js
/**
 * Base path for public API calls: path-scoped when a slug came from the URL,
 * host-scoped when we are on the salon's own subdomain.
 */
export function publicApiBase(routeSlug) {
  return routeSlug ? `/public/${routeSlug}` : '/public'
}
```

and use it in `SalonSiteView.vue`, `PublicBookingView.vue` and `ManageBookingView.vue` in place of the hardcoded `/public/${slug}` prefix.

- [ ] **Step 8: Point the banner at the working URL**

In `frontend/src/components/SubdomainBanner.vue`, the `visitUrl` computed currently falls back to `/salon/{slug}` outside production. Keep that dev fallback, but make the production branch use the domain from the store rather than a reconstructed string, so the banner and the DNS can never disagree:

```js
const visitUrl = computed(() =>
  import.meta.env.PROD ? `https://${domain.value}` : `/salon/${slug.value}`,
)
```

- [ ] **Step 9: Build and verify both host modes**

Run: `cd frontend && npm run build`
Expected: clean build.

Verify the resolver logic directly (Task 15 turns this into a real test; for now assert it by hand in a node REPL or the browser console):

```js
resolveSlugFromHost('beauty-queen.salonhub.com') // 'beauty-queen'
resolveSlugFromHost('salonhub.com')              // null
resolveSlugFromHost('app.salonhub.com')          // null
resolveSlugFromHost('salonhub.com.evil.test')    // null
resolveSlugFromHost('a.b.salonhub.com')          // null
```

- [ ] **Step 10: Full backend suite**

Run: `php artisan test --compact`
Expected: zero failures. `RegisterTest` assertions on the domain row now expect `is_verified = true` — update them if they assert the old value.

- [ ] **Step 11: Commit**

```bash
git add backend/routes/api.php backend/config/app.php backend/app/Actions/Auth/RegisterOrganization.php \
        backend/tests/Feature/Public/SubdomainResolutionTest.php \
        frontend/src/lib/tenantHost.js frontend/src/router/index.js \
        frontend/src/views/SalonSiteView.vue frontend/src/views/PublicBookingView.vue \
        frontend/src/views/ManageBookingView.vue frontend/src/components/SubdomainBanner.vue
git commit -m "feat: serve each salon's booking site from its own subdomain"
```

---

### Task 13: Error monitoring and production logging

Production has no error reporting: a 500 in a booking flow is a line in a log file nobody reads. `LOG_LEVEL=debug` and `LOG_STACK=single` also mean an unbounded file holding customer names and phone numbers.

**Files:**
- Modify: `backend/composer.json` (add `sentry/sentry-laravel`)
- Create: `backend/config/sentry.php` (published)
- Modify: `backend/bootstrap/app.php` (report handler)
- Modify: `backend/docs/deploy/env.production.example` (already carries the DSN keys from Task 9)
- Modify: `frontend/src/lib/api.js` (surface unexpected 5xx rather than swallow)

**Interfaces:**
- Consumes: `SENTRY_LARAVEL_DSN` from the production env template.
- Produces: unhandled exceptions reported with the organization id attached; an empty DSN disables reporting entirely so local and CI are unaffected.

- [ ] **Step 1: Install the SDK**

Run: `cd backend && composer require sentry/sentry-laravel`
Then: `php artisan sentry:publish --dsn=` (leave the DSN blank; it comes from env).

- [ ] **Step 2: Report exceptions, with the tenant attached**

In `backend/bootstrap/app.php`, fill the currently empty `withExceptions` closure:

```php
    ->withExceptions(function (Exceptions $exceptions): void {
        // Report to Sentry when a DSN is configured. Empty DSN (local, CI)
        // makes this a no-op, so nothing here needs a guard elsewhere.
        $exceptions->reportable(function (\Throwable $e): void {
            if (app()->bound('sentry') && config('sentry.dsn')) {
                \Sentry\Laravel\Integration::captureUnhandledException($e);
            }
        });
    });
```

- [ ] **Step 3: Tag reports with the current tenant**

In `backend/app/Http/Middleware/ResolveTenant.php`, after `$this->tenant->set(...)` in both branches, add:

```php
        // Attach the tenant to any error reported from this request, so a
        // spike can be traced to one salon rather than the whole platform.
        if (app()->bound('sentry') && ($organization = $this->tenant->get())) {
            \Sentry\configureScope(function (\Sentry\State\Scope $scope) use ($organization): void {
                $scope->setTag('organization_id', (string) $organization->id);
                $scope->setTag('organization_slug', $organization->slug);
            });
        }
```

- [ ] **Step 4: Make the frontend stop swallowing 5xx**

`frontend/src/lib/api.js` only handles 401 in its response interceptor; everything else propagates as a raw rejection each view must remember to render. Add a 5xx branch that logs once and lets the rejection continue:

```js
    if (error.response && error.response.status >= 500) {
      // A server fault is never the user's fault to interpret — surface it
      // rather than let a view render an empty state that looks like "no data".
      console.error('SalonHub API error', error.response.status, error.config?.url)
    }
```

- [ ] **Step 5: Verify it is inert without a DSN**

Run: `php artisan test --compact`
Expected: zero failures — with no `SENTRY_LARAVEL_DSN` in `.env.example`, nothing reports.

Then confirm reporting works with a DSN set, using Sentry's built-in probe:

Run (only with a real DSN in `.env`): `php artisan sentry:test`
Expected: the event appears in the Sentry project.

- [ ] **Step 6: Commit**

```bash
git add backend/composer.json backend/composer.lock backend/config/sentry.php \
        backend/bootstrap/app.php backend/app/Http/Middleware/ResolveTenant.php \
        frontend/src/lib/api.js
git commit -m "feat: report unhandled exceptions to Sentry with the tenant attached"
```

---

### Task 14: Database and upload backups

A single VPS with no backup is one `DROP` or one failed disk from losing every salon's customer list. Uploads live on the local public disk, so they are not covered by a database dump alone.

**Files:**
- Create: `backend/docs/deploy/backup.sh`
- Modify: `backend/docs/deploy/README.md` (cron entry + restore drill)

**Interfaces:**
- Consumes: the `DB_*` values from `.env`; `backend/storage/app/public` for uploads.
- Produces: a nightly `salonhub-YYYY-MM-DD.sql.gz` plus `storage-YYYY-MM-DD.tar.gz` in `/var/backups/salonhub`, 14 days retained.

- [ ] **Step 1: Write the backup script**

Create `backend/docs/deploy/backup.sh`:

```bash
#!/usr/bin/env bash
# Nightly backup: database dump + uploaded images. Reads credentials from the
# app's own .env so there is no second place to rotate a password.
set -euo pipefail

APP_DIR=/var/www/salonhub/backend
DEST=/var/backups/salonhub
RETAIN_DAYS=14
STAMP=$(date +%F)

# shellcheck disable=SC2046
export $(grep -E '^DB_(DATABASE|USERNAME|PASSWORD|HOST)=' "$APP_DIR/.env" | xargs)

mkdir -p "$DEST"

# --single-transaction keeps InnoDB consistent without locking the salon out
# mid-booking.
mysqldump --single-transaction --quick --no-tablespaces \
  -h "${DB_HOST:-127.0.0.1}" -u "$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" \
  | gzip > "$DEST/salonhub-$STAMP.sql.gz"

# Uploaded logos, covers and gallery images: not in the database.
tar -czf "$DEST/storage-$STAMP.tar.gz" -C "$APP_DIR/storage/app" public

# A backup that only exists on the machine it protects is not a backup.
# Uncomment once an off-site target is configured:
# rclone copy "$DEST" remote:salonhub-backups --max-age 25h

find "$DEST" -name '*.gz' -mtime +$RETAIN_DAYS -delete

echo "Backup complete: $DEST/salonhub-$STAMP.sql.gz"
```

Make it executable: `chmod +x backend/docs/deploy/backup.sh`

- [ ] **Step 2: Verify the script parses**

Run: `bash -n backend/docs/deploy/backup.sh`
Expected: no output.

- [ ] **Step 3: Document the cron entry and the restore drill**

Add to `backend/docs/deploy/README.md`, in the cron section beside the scheduler line:

```
15 3 * * * /var/www/salonhub/backend/docs/deploy/backup.sh >> /var/log/salonhub/backup.log 2>&1
```

And a restore section stating the exact commands, plus the instruction that a restore must be rehearsed once onto a scratch database before launch — an untested backup is an assumption:

```bash
gunzip -c /var/backups/salonhub/salonhub-2026-08-05.sql.gz | mysql -u root salonhub_restore_test
tar -xzf /var/backups/salonhub/storage-2026-08-05.tar.gz -C /tmp/restore-test
```

- [ ] **Step 4: Commit**

```bash
git add backend/docs/deploy/backup.sh backend/docs/deploy/README.md
git commit -m "docs: nightly database and upload backups with a restore drill"
```

---

### Task 15: Frontend test harness

The frontend has zero tests — `package.json` has no test script and no runner. Task 12 introduced `resolveSlugFromHost`, a pure function whose failure mode is serving one salon's page on another salon's domain. That is exactly what a unit test is for.

**Files:**
- Modify: `frontend/package.json` (vitest, jsdom, `@vue/test-utils`, `test:unit` script)
- Create: `frontend/vitest.config.js`
- Create: `frontend/src/lib/tenantHost.spec.js`
- Create: `frontend/src/stores/auth.spec.js`
- Modify: `.github/workflows/ci.yml` (add the step deferred in Task 11)

**Interfaces:**
- Consumes: `resolveSlugFromHost`, `publicApiBase` from Task 12; the `useAuthStore` pinia store.
- Produces: `npm run test:unit` as a green, CI-enforced command.

- [ ] **Step 1: Install the runner**

Run: `cd frontend && npm install -D vitest jsdom @vue/test-utils @pinia/testing`

Add to `frontend/package.json` scripts:

```json
    "test:unit": "vitest run",
    "test:watch": "vitest"
```

- [ ] **Step 2: Configure vitest**

Create `frontend/vitest.config.js`:

```js
import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
  },
  test: {
    environment: 'jsdom',
    include: ['src/**/*.spec.js'],
  },
})
```

- [ ] **Step 3: Write the host-resolver tests**

Create `frontend/src/lib/tenantHost.spec.js`:

```js
import { describe, it, expect } from 'vitest'
import { resolveSlugFromHost, publicApiBase } from './tenantHost'

describe('resolveSlugFromHost', () => {
  it('reads the slug from a salon subdomain', () => {
    expect(resolveSlugFromHost('beauty-queen.salonhub.com', 'salonhub.com')).toBe('beauty-queen')
  })

  it('returns null on the apex, which is the marketing site', () => {
    expect(resolveSlugFromHost('salonhub.com', 'salonhub.com')).toBeNull()
  })

  it('returns null for reserved product hosts', () => {
    expect(resolveSlugFromHost('app.salonhub.com', 'salonhub.com')).toBeNull()
    expect(resolveSlugFromHost('www.salonhub.com', 'salonhub.com')).toBeNull()
  })

  it('refuses a lookalike domain that merely contains the apex', () => {
    // The whole point: this must never resolve to a salon.
    expect(resolveSlugFromHost('salonhub.com.evil.test', 'salonhub.com')).toBeNull()
  })

  it('refuses a multi-label subdomain', () => {
    expect(resolveSlugFromHost('a.b.salonhub.com', 'salonhub.com')).toBeNull()
  })

  it('ignores a port and is case-insensitive', () => {
    expect(resolveSlugFromHost('Beauty-Queen.SalonHub.com:8443', 'salonhub.com')).toBe('beauty-queen')
  })
})

describe('publicApiBase', () => {
  it('is path-scoped when the slug came from the URL', () => {
    expect(publicApiBase('beauty-queen')).toBe('/public/beauty-queen')
  })

  it('is host-scoped when there is no slug in the URL', () => {
    expect(publicApiBase(undefined)).toBe('/public')
  })
})
```

- [ ] **Step 4: Run them and confirm they exercise real behaviour**

Run: `npm run test:unit`
Expected: all pass. Then deliberately break `resolveSlugFromHost` — change `bare.endsWith(\`.${appDomain}\`)` to `bare.includes(appDomain)` — and re-run. The lookalike test must FAIL. Restore the code. A test that cannot fail is not a test.

- [ ] **Step 5: Add a store test**

Create `frontend/src/stores/auth.spec.js` covering: a fresh store is unauthenticated; `setToken` persists to `localStorage` under `salonhub_token` and flips `isAuthenticated`; `logout` clears both the store and `localStorage` even when the API call rejects. Use `setActivePinia(createPinia())` in a `beforeEach` and stub the api module with `vi.mock('@/lib/api', ...)`.

- [ ] **Step 6: Wire it into CI**

In `.github/workflows/ci.yml`, add to the `frontend` job after the build step:

```yaml
      - name: Unit tests
        working-directory: frontend
        run: npm run test:unit
```

and delete the comment placed there by Task 11.

- [ ] **Step 7: Commit**

```bash
git add frontend/package.json frontend/package-lock.json frontend/vitest.config.js \
        frontend/src/lib/tenantHost.spec.js frontend/src/stores/auth.spec.js \
        .github/workflows/ci.yml
git commit -m "test: vitest harness with host-resolver and auth-store coverage"
```

---

### Task 16: Demo seeder

`DatabaseSeeder` is empty. There is no way to get a populated salon for a screenshot, a sales demo, or a manual QA pass without clicking through the whole dashboard by hand. A seeder is also the fastest end-to-end check that Tasks 4, 5 and 12 produced a genuinely bookable salon.

**Files:**
- Create: `backend/database/seeders/DemoSalonSeeder.php`
- Modify: `backend/database/seeders/DatabaseSeeder.php`

**Interfaces:**
- Consumes: `RegisterOrganization::execute()` (so the seeded salon is identical to a registered one, default branch included).
- Produces: `php artisan db:seed --class=DemoSalonSeeder` creating one org with slug `demo-salon`, owner `demo@salonhub.com` / `password`, 4 services in 2 categories, 3 staff with working hours, 12 customers and 30 appointments spread across the last and next 14 days in mixed statuses.

- [ ] **Step 1: Write the seeder**

Create `backend/database/seeders/DemoSalonSeeder.php`. Build it on top of the registration action rather than raw inserts, so the seeded salon can never drift from what a real signup produces:

```php
<?php

namespace Database\Seeders;

use App\Actions\Auth\RegisterOrganization;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * A populated salon for demos, screenshots and manual QA.
 *
 * Built on RegisterOrganization so the demo salon is byte-identical to a
 * real signup — if registration stops producing a bookable salon, this
 * seeder stops producing one too, and we find out immediately.
 */
class DemoSalonSeeder extends Seeder
{
    public function run(): void
    {
        $result = app(RegisterOrganization::class)->execute([
            'salon_name' => 'Demo Salon',
            'name' => 'Dana Demo',
            'email' => 'demo@salonhub.com',
            'password' => 'password',
            'phone' => '+8801700000000',
            'country' => 'BD',
            'timezone' => 'Asia/Dhaka',
            'currency' => 'BDT',
        ]);

        $org = $result['organization'];
        $branch = Branch::withoutGlobalScopes()->where('organization_id', $org->id)->firstOrFail();

        $hair = ServiceCategory::create(['organization_id' => $org->id, 'name' => 'Hair']);
        $skin = ServiceCategory::create(['organization_id' => $org->id, 'name' => 'Skin']);

        $services = collect([
            ['Hair Cut', $hair->id, 30, 500],
            ['Hair Colour', $hair->id, 90, 3500],
            ['Facial', $skin->id, 60, 1800],
            ['Manicure', $skin->id, 45, 900],
        ])->map(fn (array $row) => Service::create([
            'organization_id' => $org->id,
            'category_id' => $row[1],
            'name' => $row[0],
            'duration' => $row[2],
            'price' => $row[3],
            'status' => 'active',
        ]));

        $staff = collect(['Alia Rahman', 'Bipul Das', 'Chandni Roy'])->map(function (string $name) use ($org, $branch, $services) {
            $user = User::create([
                'organization_id' => $org->id,
                'branch_id' => $branch->id,
                'name' => $name,
                'email' => str($name)->slug().'@demo.salonhub.com',
                'password' => Hash::make('password'),
                'role' => 'staff',
                'status' => 'active',
            ]);

            StaffProfile::create([
                'user_id' => $user->id,
                'designation' => 'Stylist',
                'working_days_json' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'],
                'working_hours_json' => ['09:00', '18:00'],
            ]);

            // Every stylist can perform every service, so any demo booking
            // path finds an available staff member.
            $user->services()->sync($services->pluck('id')->all());

            return $user;
        });

        $customers = collect(range(1, 12))->map(fn (int $i) => Customer::create([
            'organization_id' => $org->id,
            'name' => "Demo Customer {$i}",
            'phone' => '+88017000000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            'email' => "customer{$i}@demo.test",
        ]));

        // 30 appointments across the last and next fortnight, statuses mixed
        // so the dashboard, calendar and reports all have something to show.
        $statuses = [
            AppointmentStatus::COMPLETED->value,
            AppointmentStatus::CONFIRMED->value,
            AppointmentStatus::PENDING->value,
            AppointmentStatus::CANCELLED->value,
            AppointmentStatus::NO_SHOW->value,
        ];

        foreach (range(0, 29) as $i) {
            $date = Carbon::today()->addDays($i - 14);
            $service = $services[$i % $services->count()];
            $hour = 9 + ($i % 8);

            Appointment::create([
                'organization_id' => $org->id,
                'branch_id' => $branch->id,
                'customer_id' => $customers[$i % $customers->count()]->id,
                'staff_id' => $staff[$i % $staff->count()]->id,
                'service_id' => $service->id,
                'booking_date' => $date->toDateString(),
                'start_time' => sprintf('%02d:00:00', $hour),
                'end_time' => sprintf('%02d:%02d:00', $hour + intdiv($service->duration, 60), $service->duration % 60),
                'price' => $service->price,
                // Past dates get settled statuses, future dates get open ones.
                'status' => $date->isPast() ? $statuses[$i % 5] : $statuses[($i % 2) + 1],
            ]);
        }

        $this->command->info("Demo salon ready: demo@salonhub.com / password (slug: {$org->slug})");
    }
}
```

- [ ] **Step 2: Keep `DatabaseSeeder` opt-in**

In `backend/database/seeders/DatabaseSeeder.php`, do **not** call `DemoSalonSeeder` from `run()` — `RefreshDatabase` in the test suite would then create a demo org before every test. Instead add a comment:

```php
    public function run(): void
    {
        // Demo data is opt-in, never automatic: the test suite runs migrations
        // on a fresh database and must not inherit a seeded organization.
        //   php artisan db:seed --class=DemoSalonSeeder
    }
```

- [ ] **Step 3: Run it**

Run: `php artisan migrate:fresh && php artisan db:seed --class=DemoSalonSeeder`
Expected: `Demo salon ready: demo@salonhub.com / password (slug: demo-salon)`.

- [ ] **Step 4: Verify the seeded salon is genuinely bookable end to end**

Run: `php artisan serve`, then:

```bash
curl -s "http://127.0.0.1:8000/api/public/demo-salon/site" | head -c 400
curl -s "http://127.0.0.1:8000/api/public/demo-salon/services" | head -c 400
```

Expected: the site payload includes a branch with non-empty `hours` (proves Task 5) and the services list is non-empty. Then open `/salon/demo-salon` in the browser and complete a booking through the UI. This is the plan's real end-to-end gate.

- [ ] **Step 5: Confirm the suite is unaffected**

Run: `php artisan test --compact`
Expected: zero failures.

- [ ] **Step 6: Commit**

```bash
git add backend/database/seeders/DemoSalonSeeder.php backend/database/seeders/DatabaseSeeder.php
git commit -m "feat: demo salon seeder built on the real registration action"
```

---

### Task 17: Repo hygiene

Three small things that cost nothing and confuse everyone who touches the repo next.

**Files:**
- Delete: `frontend/src/assets/base.css`
- Rename: `# CLAUDE.md` → `CLAUDE.md`
- Create: `README.md` (repo root)

**Interfaces:**
- Consumes: nothing.
- Produces: `CLAUDE.md` at the path Claude Code actually reads.

- [ ] **Step 1: Delete the dead stylesheet**

`frontend/src/assets/base.css` holds a leftover Inter/Roboto scaffold stack and is imported nowhere — flagged during the marketing-site review and deferred. Confirm, then delete:

Run: `grep -rn "base.css" frontend/src frontend/index.html`
Expected: no matches. Then: `git rm frontend/src/assets/base.css`

- [ ] **Step 2: Fix the CLAUDE.md filename**

The project brief lives in a file literally named `# CLAUDE.md` — with a leading hash and space, apparently from a pasted markdown heading. Claude Code loads `CLAUDE.md`, so the brief is currently never picked up:

```bash
git mv "# CLAUDE.md" CLAUDE.md
```

- [ ] **Step 3: Write a root README**

There is no README at all. Create `README.md` covering: what SalonHub is (one paragraph from the brief's vision); the stack; local setup for both halves —

```bash
# Backend
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed --class=DemoSalonSeeder   # optional demo data
php artisan serve

# Frontend
cd frontend
npm install
npm run dev
```

— how to run the tests (`php artisan test`, `npm run test:unit`); where the docs live (`CLAUDE.md` for the product brief, `backend/docs/deploy/README.md` for production, `docs/superpowers/plans/` for implementation plans); and the demo credentials the seeder prints.

- [ ] **Step 4: Verify the frontend still builds without base.css**

Run: `cd frontend && npm run build`
Expected: clean build.

- [ ] **Step 5: Commit**

```bash
git add README.md CLAUDE.md
git rm frontend/src/assets/base.css
git commit -m "chore: root README, fix CLAUDE.md filename, drop dead base.css"
```

---

### Task 18: Make the pricing page tell the truth

`PricingSection.vue` advertises plans, but the only enforcement that exists is `PlanLimit` (1 branch, 10 staff on Free). There is no billing, no subscription state machine, no upgrade path — and shipping a page that sells a Starter tier nobody can buy is the kind of thing that generates support email on day one. Decide once: **v1 launches Free-only**, and the page says so.

**Files:**
- Modify: `frontend/src/components/marketing/PricingSection.vue`
- Test: `backend/tests/Feature/Crud/PlanLimitTest.php` (create)

**Interfaces:**
- Consumes: `App\Services\PlanLimit` as used by `BranchController::store` and `StaffController::store`.
- Produces: pricing copy matching enforced behaviour; a test proving both limits are actually enforced rather than merely intended.

- [ ] **Step 1: Write the failing (or confirming) test**

Create `backend/tests/Feature/Crud/PlanLimitTest.php` asserting the two limits the marketing page will now promise. The org already has one branch from Task 4, so the *first* branch an owner creates is the one that must be refused:

```php
<?php

namespace Tests\Feature\Crud;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlanLimitTest extends TestCase
{
    use RefreshDatabase;

    private function registerOwner(): array
    {
        $res = $this->postJson('/api/auth/register', [
            'salon_name' => 'Limit Salon',
            'name' => 'Owner',
            'email' => 'owner@limit.test',
            'password' => 'secret1234',
            'password_confirmation' => 'secret1234',
        ])->assertStatus(201);

        return [$res->json('token'), Organization::where('slug', 'limit-salon')->firstOrFail()];
    }

    public function test_free_plan_refuses_a_second_branch(): void
    {
        [$token] = $this->registerOwner();

        // Registration already created the one branch the Free plan allows.
        $this->withToken($token)
            ->postJson('/api/branches', ['name' => 'Second Location'])
            ->assertStatus(422);
    }

    public function test_free_plan_refuses_the_eleventh_staff_member(): void
    {
        [$token] = $this->registerOwner();

        for ($i = 1; $i <= 10; $i++) {
            $this->withToken($token)->postJson('/api/staff', [
                'name' => "Stylist {$i}",
                'email' => "stylist{$i}@limit.test",
                'password' => 'secret1234',
            ])->assertStatus(201);
        }

        $this->withToken($token)->postJson('/api/staff', [
            'name' => 'Stylist 11',
            'email' => 'stylist11@limit.test',
            'password' => 'secret1234',
        ])->assertStatus(422);
    }
}
```

- [ ] **Step 2: Run it**

Run: `php artisan test tests/Feature/Crud/PlanLimitTest.php`
Expected: PASS if `PlanLimit` already enforces both. If either fails, that is a real gap — fix `PlanLimit` / the controller before touching the marketing copy, because the page must not promise a limit the API does not hold. Note the staff test posts 11 times through the register-throttled client; `/api/staff` is not throttled, but if the registration in `registerOwner` trips Task 3's `throttle:3,1` across the two test methods, add `$this->travel(61)->seconds();` at the top of the second test.

- [ ] **Step 3: Rewrite the pricing copy**

In `frontend/src/components/marketing/PricingSection.vue`, keep the Free card as the single purchasable plan, with the enforced limits stated exactly: **1 branch**, **10 staff**, unlimited services, unlimited customers, booking website, calendar, reports, email reminders. Replace any Starter/Business cards that offer a checkout with a single muted "More plans coming" panel — no price, no CTA, no signup — carrying copy such as:

> More branches, more staff and custom domains are on the way. Start free; we'll tell you before anything changes.

- [ ] **Step 4: Check the FAQ agrees**

Run: `grep -n "plan\|price\|free\|Starter\|Business" frontend/src/components/marketing/FaqSection.vue frontend/src/components/marketing/HeroSection.vue frontend/src/components/marketing/FeaturesSection.vue`
Fix any copy that implies a paid tier is purchasable today. The pricing section is not the only place a promise can leak.

- [ ] **Step 5: Build**

Run: `cd frontend && npm run build`
Expected: clean build.

- [ ] **Step 6: Full backend suite**

Run: `php artisan test --compact`
Expected: zero failures.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/components/marketing/PricingSection.vue \
        backend/tests/Feature/Crud/PlanLimitTest.php
git commit -m "feat: pricing page states only the limits the API enforces"
```

---

## Release Gate

Before tagging `v1.0.0`, every one of these must be true:

- [ ] `php artisan test` — zero failures
- [ ] `npm run test:unit` — zero failures
- [ ] `npm run build` — clean
- [ ] `git status --short` — empty
- [ ] CI green on `main`
- [ ] A fresh registration on the production host ends with a bookable salon: default branch present, hours visible on the public site, verification email received
- [ ] A booking made through `<slug>.salonhub.com` reaches the dashboard and its confirmation email arrives (proves the queue worker runs)
- [ ] `php artisan schedule:list` on the server shows `reminders:send` and `bookings:release-abandoned`
- [ ] `supervisorctl status salonhub-worker:*` — RUNNING
- [ ] A backup has been taken **and restored** onto a scratch database
- [ ] `/terms`, `/privacy`, `/refund` reachable from the footer on the production host
- [ ] `APP_DEBUG=false` confirmed on the server: `php artisan tinker --execute="echo config('app.debug') ? 'LEAKING' : 'ok';"`

---

## Task Dependency Order

Strictly sequential where noted, otherwise independent:

```
T1 (green suite)  ─┬─> T3 (throttle)
                   ├─> T4 (default branch) ──> T5 (public hours) ──> T16 (seeder, e2e gate)
                   └─> T7 (SPA fallback) ─┐
T2 (clean tree)    ────────────────────── ┤
T8 (CORS) ──────────────────────────────  ┼─> T10 (deploy runbook) ──> T14 (backups)
T9 (prod env) ─────────────────────────── ┘
T12 (subdomain) depends on T8 + T10; T15 (vitest) depends on T12
T6 (legal), T11 (CI), T13 (Sentry), T17 (hygiene), T18 (pricing) — independent
```

Recommended execution order: **1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18** — the numbering already respects every dependency.
