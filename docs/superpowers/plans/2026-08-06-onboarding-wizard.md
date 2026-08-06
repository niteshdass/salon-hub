# Onboarding Wizard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Take a salon owner from first login to a shareable, working booking link in four guided steps.

**Architecture:** A frontend wizard at `/onboarding` drives the existing entity endpoints (`PUT /branches/{id}`, `settings/organization`) plus three new ones (`GET /onboarding/status`, `POST /onboarding/complete`, `POST /services/bulk`, `GET /service-presets`). Step completion is **derived by counting real rows**, never stored as a step counter; the only new column is `organizations.onboarding_completed_at`, which records that the owner is done being asked.

**Tech Stack:** Laravel 12 / PHP 8.4, PHPUnit feature tests with `RefreshDatabase`; Vue 3 `<script setup>`, Pinia, Vue Router, Tailwind v4, Vitest + jsdom.

**Spec:** `docs/superpowers/specs/2026-08-06-onboarding-wizard-design.md`

## Global Constraints

- Tests are **PHPUnit**, not Pest. Class per file under `backend/tests/Feature/<Area>/`, `use RefreshDatabase`, methods named `test_snake_case_sentence(): void`.
- Backend tests build their own tenant inline (see `tests/Feature/Crud/ServiceCrudTest.php::makeOrgWithOwner`) and authenticate with `$this->withToken($token)` where `$token = $user->createToken('api')->plainTextToken`.
- Every tenant-scoped route lives inside the `['auth:sanctum', 'tenant']` group in `backend/routes/api.php`. Never scope by `organization_id` by hand on a model that uses `BelongsToOrganization` — it is already global-scoped. `User` is the exception: it is the auth model, is **not** auto-scoped, and must be filtered by `organization_id` **and** `role` manually.
- Owner-only endpoints authorize with `$this->authorize('update', Organization::class)` (class-level `OrganizationPolicy`).
- Frontend tests are `src/**/*.spec.js`, run with `npm run test:unit`. Mock `@/lib/api` with `importOriginal` so real exports (`TOKEN_KEY`) stay real — copy the pattern at the top of `src/stores/auth.spec.js`.
- Weekday keys in `branches.opening_hours_json` are lowercase three-letter (`mon`…`sun`); a day is either `[open, close]` in `H:i` or `null` for closed. `SlotGenerator` reads exactly this shape.
- Free plan caps: `PlanLimit::FREE_MAX_BRANCHES = 1`, `FREE_MAX_STAFF = 10`. There is **no** service cap.
- Staff = `User(role=staff)` + `StaffProfile`. Owners are never staff.
- Tailwind classes follow the existing views; the standard text input is:
  `class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"`
- Commit after every task. Backend commands run from `backend/`, frontend from `frontend/`.

## File Structure

**Backend (create)**
- `database/migrations/2026_08_06_000001_add_onboarding_completed_at_to_organizations_table.php`
- `app/Http/Controllers/OnboardingController.php` — status + complete
- `app/Services/OnboardingStatus.php` — derives the four step booleans; the controller stays thin
- `app/Http/Controllers/ServicePresetController.php` — serves the preset config
- `config/service_presets.php` — five salon types, ~8 services each
- `app/Http/Requests/Service/BulkStoreServiceRequest.php`
- `tests/Feature/Onboarding/OnboardingStatusTest.php`, `ServicePresetTest.php`, `BulkServiceTest.php`, `StaffWithoutEmailTest.php`

**Backend (modify)**
- `app/Models/Organization.php` — cast
- `app/Http/Resources/OrganizationResource.php` — expose the timestamp
- `app/Http/Controllers/ServiceController.php` — `bulkStore`
- `app/Http/Requests/Staff/StoreStaffRequest.php` — email nullable
- `app/Http/Controllers/StaffController.php` — mint synthetic address
- `routes/api.php`

**Frontend (create)**
- `src/stores/onboarding.js` — status fetch, step derivation, completion
- `src/layouts/OnboardingLayout.vue` — bare shell: progress dots, back, skip
- `src/views/onboarding/OnboardingView.vue` — step host and router
- `src/views/onboarding/StepBranch.vue`, `StepServices.vue`, `StepStaff.vue`, `StepLook.vue`, `StepDone.vue`
- `src/lib/qrPoster.js` — canvas → PNG download
- `src/components/SetupChecklistCard.vue` — the dashboard card
- `src/stores/onboarding.spec.js`, `src/router/guard.spec.js`, `src/lib/qrPoster.spec.js`

**Frontend (modify)**
- `src/router/index.js` — route + guard clause
- `src/views/DashboardView.vue` — mount the checklist card
- `package.json` — `qrcode`

One file per screen keeps each under ~250 lines and lets a reviewer reject one step without touching its neighbours.

---

### Task 1: Record that onboarding is finished

**Files:**
- Create: `backend/database/migrations/2026_08_06_000001_add_onboarding_completed_at_to_organizations_table.php`
- Modify: `backend/app/Models/Organization.php` (the `casts()` method)
- Modify: `backend/app/Http/Resources/OrganizationResource.php`
- Test: `backend/tests/Feature/Onboarding/OnboardingStatusTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `organizations.onboarding_completed_at` (nullable timestamp, cast to `datetime`), surfaced on every `OrganizationResource` payload — including `/auth/me` — as `onboarding_completed_at`, an ISO-8601 string or `null`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Onboarding/OnboardingStatusTest.php`:

```php
<?php

namespace Tests\Feature\Onboarding;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OnboardingStatusTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Organization, 1: User, 2: string}
     */
    private function makeOrgWithOwner(string $slug): array
    {
        $org = Organization::create([
            'uuid' => (string) Str::uuid(),
            'name' => ucfirst($slug),
            'slug' => $slug,
            'email' => "owner@{$slug}.test",
            'subscription_plan' => 'free',
            'status' => 'active',
        ]);

        $owner = User::create([
            'organization_id' => $org->id,
            'name' => "{$slug} owner",
            'email' => "owner@{$slug}.test",
            'password' => 'secret1234',
            'role' => 'owner',
            'status' => 'active',
        ]);

        return [$org, $owner, $owner->createToken('api')->plainTextToken];
    }

    public function test_me_reports_a_fresh_organization_as_not_onboarded(): void
    {
        [, , $token] = $this->makeOrgWithOwner('alpha');

        $response = $this->withToken($token)->getJson('/api/auth/me');

        $response->assertOk();
        // Both assertions, deliberately. assertJsonPath alone cannot fail
        // here: a missing key and a key holding null both read as null, so
        // it would pass against a resource that never exposed the field.
        // The structural assertion is what actually guards that exposure.
        $response->assertJsonStructure(['organization' => ['onboarding_completed_at']]);
        $response->assertJsonPath('organization.onboarding_completed_at', null);
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test --filter=test_me_reports_a_fresh_organization_as_not_onboarded`
Expected: FAIL on the structural assertion — `organization.onboarding_completed_at` is missing from the payload.

- [ ] **Step 3: Add the column**

Create the migration:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            // Null means "still being asked". Stamped means the owner has
            // either finished the wizard or dismissed it for good; the SPA
            // reads it to decide whether to open the wizard on login.
            $table->timestamp('onboarding_completed_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('onboarding_completed_at');
        });
    }
};
```

- [ ] **Step 4: Cast it and expose it**

In `app/Models/Organization.php`, add to the array returned by `casts()`:

```php
'onboarding_completed_at' => 'datetime',
```

Do **not** add it to `$fillable` — it is only ever set explicitly by the controller in Task 2.

In `app/Http/Resources/OrganizationResource.php`, add below `'status' => ...`:

```php
'onboarding_completed_at' => $this->onboarding_completed_at?->toIso8601String(),
```

- [ ] **Step 5: Run the test and watch it pass**

Run: `php artisan test --filter=OnboardingStatusTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/database/migrations backend/app/Models/Organization.php backend/app/Http/Resources/OrganizationResource.php backend/tests/Feature/Onboarding
git commit -m "feat: record when a salon has finished onboarding"
```

---

### Task 2: Report which setup steps are done

**Files:**
- Create: `backend/app/Services/OnboardingStatus.php`
- Create: `backend/app/Http/Controllers/OnboardingController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Onboarding/OnboardingStatusTest.php` (extend Task 1's file)

**Interfaces:**
- Consumes: `organizations.onboarding_completed_at` (Task 1).
- Produces:
  - `OnboardingStatus::forCurrentTenant(): array` returning
    `['completed_at' => ?string, 'branch_id' => ?int, 'steps' => ['branch' => bool, 'services' => bool, 'staff' => bool, 'look' => bool], 'next_step' => string]`
    where `next_step` is one of `branch|services|staff|look|done`.
  - `GET /api/onboarding/status` → `{"data": <that array>}`
  - `POST /api/onboarding/complete` → `{"data": <that array>}` with `completed_at` set.

- [ ] **Step 1: Write the failing tests**

Append to `OnboardingStatusTest.php` (inside the class):

```php
    public function test_a_fresh_salon_has_no_step_done_and_starts_at_branch(): void
    {
        [$org, , $token] = $this->makeOrgWithOwner('alpha');
        $branch = Branch::create([
            'organization_id' => $org->id,
            'name' => 'Alpha',
        ]);

        $response = $this->withToken($token)->getJson('/api/onboarding/status');

        $response->assertOk();
        $response->assertJsonPath('data.steps.branch', false);
        $response->assertJsonPath('data.steps.services', false);
        $response->assertJsonPath('data.steps.staff', false);
        $response->assertJsonPath('data.steps.look', false);
        $response->assertJsonPath('data.next_step', 'branch');
        $response->assertJsonPath('data.branch_id', $branch->id);
    }

    public function test_each_step_flips_when_its_rows_exist(): void
    {
        [$org, , $token] = $this->makeOrgWithOwner('alpha');
        Branch::create([
            'organization_id' => $org->id,
            'name' => 'Alpha',
            'address' => '12 Green Road',
        ]);
        Service::create([
            'organization_id' => $org->id,
            'name' => 'Hair cut',
            'duration' => 30,
            'price' => 12,
            'status' => 'active',
        ]);
        User::create([
            'organization_id' => $org->id,
            'name' => 'Ruma',
            'email' => 'ruma@alpha.test',
            'password' => 'secret1234',
            'role' => 'staff',
            'status' => 'active',
        ]);
        Setting::create([
            'organization_id' => $org->id,
            'about' => 'We have cut hair on this street since 1998.',
        ]);

        $response = $this->withToken($token)->getJson('/api/onboarding/status');

        $response->assertJsonPath('data.steps.branch', true);
        $response->assertJsonPath('data.steps.services', true);
        $response->assertJsonPath('data.steps.staff', true);
        $response->assertJsonPath('data.steps.look', true);
        $response->assertJsonPath('data.next_step', 'done');
    }

    public function test_the_owner_of_another_salon_sees_only_their_own_progress(): void
    {
        [$orgA] = $this->makeOrgWithOwner('alpha');
        [, , $tokenB] = $this->makeOrgWithOwner('bravo');
        Service::create([
            'organization_id' => $orgA->id,
            'name' => 'Hair cut',
            'duration' => 30,
            'price' => 12,
            'status' => 'active',
        ]);

        $response = $this->withToken($tokenB)->getJson('/api/onboarding/status');

        $response->assertJsonPath('data.steps.services', false);
    }

    public function test_a_manager_may_not_read_or_complete_onboarding(): void
    {
        [$org] = $this->makeOrgWithOwner('alpha');
        $manager = User::create([
            'organization_id' => $org->id,
            'name' => 'Manager',
            'email' => 'manager@alpha.test',
            'password' => 'secret1234',
            'role' => 'manager',
            'status' => 'active',
        ]);
        $token = $manager->createToken('api')->plainTextToken;

        $this->withToken($token)->getJson('/api/onboarding/status')->assertForbidden();
        $this->withToken($token)->postJson('/api/onboarding/complete')->assertForbidden();
    }

    public function test_the_look_step_is_satisfied_by_a_logo_alone(): void
    {
        [$org, , $token] = $this->makeOrgWithOwner('alpha');
        // No settings row at all. `look` is an OR of two columns, and without
        // this case the logo half of it is never exercised.
        $org->logo = 'logos/alpha.png';
        $org->save();

        $this->withToken($token)
            ->getJson('/api/onboarding/status')
            ->assertJsonPath('data.steps.look', true);
    }

    public function test_completing_is_idempotent_and_keeps_the_first_timestamp(): void
    {
        [$org, , $token] = $this->makeOrgWithOwner('alpha');

        $first = $this->withToken($token)->postJson('/api/onboarding/complete');
        $first->assertOk();
        $this->assertNotNull($first->json('data.completed_at'));

        $stamped = $org->fresh()->onboarding_completed_at;

        // Advance the clock. The column has one-second resolution, so without
        // this both calls land inside the same second and a controller that
        // re-stamped on EVERY call would be byte-identical to a correct one —
        // the test would pass against the bug it exists to catch.
        $this->travel(2)->seconds();

        $second = $this->withToken($token)->postJson('/api/onboarding/complete');
        $second->assertOk();

        $this->assertTrue($stamped->equalTo($org->fresh()->onboarding_completed_at));
    }
```

Add the imports the new tests need at the top of the file:

```php
use App\Models\Branch;
use App\Models\Service;
use App\Models\Setting;
```

- [ ] **Step 2: Run them and watch them fail**

Run: `php artisan test --filter=OnboardingStatusTest`
Expected: FAIL — `/api/onboarding/status` is a 404, the route does not exist.

- [ ] **Step 3: Write the status service**

Create `backend/app/Services/OnboardingStatus.php`:

```php
<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Tenancy\CurrentTenant;

/**
 * Which setup steps a salon has finished.
 *
 * Every answer is DERIVED from the rows the booking engine itself reads.
 * There is deliberately no stored step counter: a counter can disagree
 * with reality (a service deleted after the wizard ran, a branch address
 * cleared in Settings), and the version that disagrees is the one the
 * owner is shown. Counting rows cannot drift.
 */
class OnboardingStatus
{
    /**
     * Every step, in the order the wizard asks them. The frontend store keeps
     * its own list of which of these block a booking; the backend only reports
     * what is done and does not need a second copy of that rule.
     */
    public const STEPS = ['branch', 'services', 'staff', 'look'];

    public function __construct(protected CurrentTenant $tenant) {}

    /**
     * @return array{completed_at: ?string, branch_id: ?int, steps: array<string, bool>, next_step: string}
     */
    public function forCurrentTenant(): array
    {
        $organization = $this->tenant->get();

        // Branch and Service use BelongsToOrganization, so they are already
        // scoped to the bound tenant. User is the auth model and is not.
        $branch = Branch::query()->orderBy('id')->first();
        $settings = Setting::query()->first();

        $steps = [
            'branch' => filled($branch?->address),
            'services' => Service::query()->exists(),
            'staff' => User::query()
                ->where('organization_id', $this->tenant->id())
                ->where('role', UserRole::STAFF->value)
                ->exists(),
            'look' => filled($settings?->about) || filled($organization?->logo),
        ];

        return [
            'completed_at' => $organization?->onboarding_completed_at?->toIso8601String(),
            'branch_id' => $branch?->id,
            'steps' => $steps,
            'next_step' => $this->nextStep($steps),
        ];
    }

    /**
     * The first unfinished step, or 'done' when nothing is left. This is
     * where the wizard resumes, so an owner who quit after services is
     * asked about staff and not about anything they already answered.
     *
     * @param  array<string, bool>  $steps
     */
    protected function nextStep(array $steps): string
    {
        foreach (self::STEPS as $step) {
            if (! $steps[$step]) {
                return $step;
            }
        }

        return 'done';
    }
}
```

- [ ] **Step 4: Write the controller**

Create `backend/app/Http/Controllers/OnboardingController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\OnboardingStatus;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;

/**
 * Guided first-run setup. Owner-only: a manager or staff member joins a
 * salon someone else has already configured.
 */
class OnboardingController extends Controller
{
    public function __construct(
        protected OnboardingStatus $status,
        protected CurrentTenant $tenant,
    ) {}

    public function status(): JsonResponse
    {
        $this->authorize('update', Organization::class);

        return response()->json(['data' => $this->status->forCurrentTenant()]);
    }

    /**
     * Stop asking. Called when the owner finishes the wizard or dismisses
     * the dashboard card.
     *
     * Idempotent, and deliberately does not re-stamp: the timestamp
     * answers "when did they stop being new", and a second call from a
     * double-tapped button must not move that.
     */
    public function complete(): JsonResponse
    {
        $this->authorize('update', Organization::class);

        $organization = $this->tenant->get();

        if ($organization->onboarding_completed_at === null) {
            $organization->onboarding_completed_at = now();
            $organization->save();
        }

        return response()->json(['data' => $this->status->forCurrentTenant()]);
    }
}
```

- [ ] **Step 5: Register the routes**

In `backend/routes/api.php`, add the import beside the other controllers:

```php
use App\Http\Controllers\OnboardingController;
```

and inside the existing `Route::middleware(['auth:sanctum', 'tenant'])->group(...)`, just above `Route::get('dashboard', DashboardController::class);`:

```php
    // First-run setup. Owner-only; the policy check lives in the controller.
    Route::get('onboarding/status', [OnboardingController::class, 'status']);
    Route::post('onboarding/complete', [OnboardingController::class, 'complete']);
```

- [ ] **Step 6: Run the tests and watch them pass**

Run: `php artisan test --filter=OnboardingStatusTest`
Expected: PASS, all seven tests.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Services/OnboardingStatus.php backend/app/Http/Controllers/OnboardingController.php backend/routes/api.php backend/tests/Feature/Onboarding/OnboardingStatusTest.php
git commit -m "feat: report onboarding progress from the rows that decide it"
```

---

### Task 3: Serve the service presets

**Files:**
- Create: `backend/config/service_presets.php`
- Create: `backend/app/Http/Controllers/ServicePresetController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Onboarding/ServicePresetTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `GET /api/service-presets` → `{"data": [{"key": "hair", "label": "Hair salon", "services": [{"name": "Hair cut", "duration": 30}, ...]}, ...]}`. No prices — those vary by country and are always typed by the owner.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Onboarding/ServicePresetTest.php`:

```php
<?php

namespace Tests\Feature\Onboarding;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ServicePresetTest extends TestCase
{
    use RefreshDatabase;

    private function token(string $slug, string $role = 'owner'): string
    {
        $org = Organization::create([
            'uuid' => (string) Str::uuid(),
            'name' => ucfirst($slug),
            'slug' => $slug,
            'email' => "owner@{$slug}.test",
            'subscription_plan' => 'free',
            'status' => 'active',
        ]);

        return User::create([
            'organization_id' => $org->id,
            'name' => "{$slug} owner",
            'email' => "owner@{$slug}.test",
            'password' => 'secret1234',
            'role' => $role,
            'status' => 'active',
        ])->createToken('api')->plainTextToken;
    }

    public function test_it_lists_every_salon_type_with_named_services(): void
    {
        $response = $this->withToken($this->token('alpha'))->getJson('/api/service-presets');

        $response->assertOk();
        $this->assertSame(
            ['hair', 'beauty', 'barber', 'spa', 'nails'],
            array_column($response->json('data'), 'key'),
        );

        foreach ($response->json('data') as $type) {
            $this->assertNotEmpty($type['label']);
            $this->assertNotEmpty($type['services']);
            foreach ($type['services'] as $service) {
                $this->assertNotEmpty($service['name']);
                $this->assertIsInt($service['duration']);
                $this->assertGreaterThan(0, $service['duration']);
                // Prices are the owner's to set; a preset must never carry one.
                $this->assertArrayNotHasKey('price', $service);
            }
        }
    }

    public function test_it_requires_a_signed_in_member(): void
    {
        $this->getJson('/api/service-presets')->assertUnauthorized();
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test --filter=ServicePresetTest`
Expected: FAIL — 404, the route does not exist.

- [ ] **Step 3: Write the config**

Create `backend/config/service_presets.php`:

```php
<?php

/**
 * Starter menus offered on the second wizard screen, one per salon type.
 *
 * Durations only. Price varies by city and currency, is the one value the
 * system cannot guess, and is therefore the only field the owner is
 * required to type on that screen.
 *
 * Config rather than a frontend constant so the wording and durations can
 * change without rebuilding the SPA.
 */
return [
    [
        'key' => 'hair',
        'label' => 'Hair salon',
        'services' => [
            ['name' => 'Hair cut', 'duration' => 30],
            ['name' => 'Hair wash & blow dry', 'duration' => 30],
            ['name' => 'Hair colour', 'duration' => 90],
            ['name' => 'Highlights', 'duration' => 120],
            ['name' => 'Hair spa', 'duration' => 60],
            ['name' => 'Straightening', 'duration' => 120],
            ['name' => 'Trim', 'duration' => 20],
            ['name' => 'Kids cut', 'duration' => 20],
        ],
    ],
    [
        'key' => 'beauty',
        'label' => 'Beauty parlour',
        'services' => [
            ['name' => 'Facial', 'duration' => 60],
            ['name' => 'Threading', 'duration' => 15],
            ['name' => 'Waxing (full arms)', 'duration' => 30],
            ['name' => 'Waxing (full legs)', 'duration' => 45],
            ['name' => 'Manicure', 'duration' => 45],
            ['name' => 'Pedicure', 'duration' => 45],
            ['name' => 'Bridal makeup', 'duration' => 120],
            ['name' => 'Party makeup', 'duration' => 60],
        ],
    ],
    [
        'key' => 'barber',
        'label' => 'Barber',
        'services' => [
            ['name' => 'Hair cut', 'duration' => 30],
            ['name' => 'Beard trim', 'duration' => 15],
            ['name' => 'Shave', 'duration' => 20],
            ['name' => 'Hair cut & beard', 'duration' => 45],
            ['name' => 'Head massage', 'duration' => 20],
            ['name' => 'Hair colour', 'duration' => 45],
            ['name' => 'Kids cut', 'duration' => 20],
            ['name' => 'Face cleanup', 'duration' => 30],
        ],
    ],
    [
        'key' => 'spa',
        'label' => 'Spa',
        'services' => [
            ['name' => 'Full body massage', 'duration' => 60],
            ['name' => 'Head & shoulder massage', 'duration' => 30],
            ['name' => 'Aroma therapy', 'duration' => 90],
            ['name' => 'Body scrub', 'duration' => 60],
            ['name' => 'Foot massage', 'duration' => 30],
            ['name' => 'Steam & sauna', 'duration' => 45],
            ['name' => 'Couple massage', 'duration' => 90],
            ['name' => 'Back massage', 'duration' => 30],
        ],
    ],
    [
        'key' => 'nails',
        'label' => 'Nails',
        'services' => [
            ['name' => 'Manicure', 'duration' => 45],
            ['name' => 'Pedicure', 'duration' => 45],
            ['name' => 'Gel polish', 'duration' => 60],
            ['name' => 'Nail extension', 'duration' => 90],
            ['name' => 'Nail art', 'duration' => 45],
            ['name' => 'Polish change', 'duration' => 20],
            ['name' => 'Nail repair', 'duration' => 30],
            ['name' => 'French manicure', 'duration' => 60],
        ],
    ],
];
```

- [ ] **Step 4: Write the controller and route**

Create `backend/app/Http/Controllers/ServicePresetController.php`:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * Starter service menus for the onboarding wizard. Static config, no
 * tenant data — but authenticated, because it is only ever needed by a
 * signed-in owner and there is no reason to serve it to the world.
 */
class ServicePresetController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => config('service_presets')]);
    }
}
```

In `routes/api.php` add the import and, beside the onboarding routes inside the same `['auth:sanctum', 'tenant']` group:

```php
    Route::get('service-presets', ServicePresetController::class);
```

- [ ] **Step 5: Run the tests and watch them pass**

Run: `php artisan test --filter=ServicePresetTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/config/service_presets.php backend/app/Http/Controllers/ServicePresetController.php backend/routes/api.php backend/tests/Feature/Onboarding/ServicePresetTest.php
git commit -m "feat: offer starter service menus per salon type"
```

---

### Task 4: Create a whole service menu in one request

**Files:**
- Create: `backend/app/Http/Requests/Service/BulkStoreServiceRequest.php`
- Modify: `backend/app/Http/Controllers/ServiceController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Onboarding/BulkServiceTest.php`

**Interfaces:**
- Consumes: `ServicePolicy::create` (already exists, used by `StoreServiceRequest`).
- Produces: `POST /api/services/bulk` accepting
  `{"category": "Hair salon", "rows": [{"name": "Hair cut", "duration": 30, "price": 12.5}, ...]}`
  and returning `201` with `{"data": [ServiceResource, ...]}`. Row errors are keyed `rows.<index>.<field>`.

- [ ] **Step 1: Write the failing tests**

Create `backend/tests/Feature/Onboarding/BulkServiceTest.php`:

```php
<?php

namespace Tests\Feature\Onboarding;

use App\Models\Organization;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BulkServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Organization, 1: string}
     */
    private function makeOrgWithOwner(string $slug): array
    {
        $org = Organization::create([
            'uuid' => (string) Str::uuid(),
            'name' => ucfirst($slug),
            'slug' => $slug,
            'email' => "owner@{$slug}.test",
            'subscription_plan' => 'free',
            'status' => 'active',
        ]);

        $owner = User::create([
            'organization_id' => $org->id,
            'name' => "{$slug} owner",
            'email' => "owner@{$slug}.test",
            'password' => 'secret1234',
            'role' => 'owner',
            'status' => 'active',
        ]);

        return [$org, $owner->createToken('api')->plainTextToken];
    }

    public function test_it_creates_every_row_under_one_new_category(): void
    {
        [$org, $token] = $this->makeOrgWithOwner('alpha');

        $response = $this->withToken($token)->postJson('/api/services/bulk', [
            'category' => 'Hair salon',
            'rows' => [
                ['name' => 'Hair cut', 'duration' => 30, 'price' => 12.5],
                ['name' => 'Hair colour', 'duration' => 90, 'price' => 40],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonCount(2, 'data');

        $this->assertSame(1, ServiceCategory::where('organization_id', $org->id)->count());
        $categoryId = ServiceCategory::where('organization_id', $org->id)->value('id');

        $this->assertDatabaseHas('services', [
            'organization_id' => $org->id,
            'category_id' => $categoryId,
            'name' => 'Hair cut',
            'duration' => 30,
            'status' => 'active',
        ]);
    }

    public function test_a_second_call_for_the_same_type_reuses_the_category(): void
    {
        [$org, $token] = $this->makeOrgWithOwner('alpha');
        $payload = [
            'category' => 'Hair salon',
            'rows' => [['name' => 'Trim', 'duration' => 20, 'price' => 5]],
        ];

        $this->withToken($token)->postJson('/api/services/bulk', $payload)->assertCreated();
        $this->withToken($token)->postJson('/api/services/bulk', $payload)->assertCreated();

        $this->assertSame(1, ServiceCategory::where('organization_id', $org->id)->count());
    }

    public function test_a_row_without_a_price_fails_and_nothing_is_written(): void
    {
        [$org, $token] = $this->makeOrgWithOwner('alpha');

        $response = $this->withToken($token)->postJson('/api/services/bulk', [
            'category' => 'Hair salon',
            'rows' => [
                ['name' => 'Hair cut', 'duration' => 30, 'price' => 12.5],
                ['name' => 'Hair colour', 'duration' => 90],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('rows.1.price');
        $this->assertDatabaseCount('services', 0);
        $this->assertDatabaseCount('service_categories', 0);
    }

    /**
     * The test above proves validation runs before the transaction is ever
     * entered — it does not prove the transaction rolls anything back, since
     * FormRequest rejects that payload before bulkStore() runs at all. This
     * test forces the failure *inside* the transaction body instead: row one
     * is valid and would be written first, row two carries a price that
     * clears `numeric|min:0` validation but exceeds what services.price
     * (decimal(10,2), 8 integer digits) can store, so it throws at the
     * database layer only after row one — and the category — already exist
     * in the (uncommitted) transaction.
     *
     * SQLite has no precision/scale enforcement on a NUMERIC-affinity
     * column — the same insert silently succeeds there — so this failure is
     * only observable on a real MySQL connection, which is why this repo
     * runs a dedicated backend-mysql CI job alongside the default SQLite one
     * (see DatabaseDriverTest). Skipped rather than faked off MySQL.
     */
    public function test_a_row_that_overflows_the_price_column_rolls_back_the_whole_menu(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped(
                'services.price is decimal(10,2); only MySQL enforces that range '
                .'at the database layer, so only there does this row fail after '
                .'row one is already written inside the transaction. SQLite has '
                .'no such enforcement — see backend-mysql in ci.yml.'
            );
        }

        [$org, $token] = $this->makeOrgWithOwner('alpha');

        $response = $this->withToken($token)->postJson('/api/services/bulk', [
            'category' => 'Hair salon',
            'rows' => [
                ['name' => 'Hair cut', 'duration' => 30, 'price' => 12.5],
                ['name' => 'Overflow', 'duration' => 30, 'price' => 100000000],
            ],
        ]);

        $response->assertServerError();
        $this->assertDatabaseCount('services', 0);
        $this->assertDatabaseCount('service_categories', 0);
    }

    public function test_it_rejects_an_empty_row_list(): void
    {
        [, $token] = $this->makeOrgWithOwner('alpha');

        $this->withToken($token)
            ->postJson('/api/services/bulk', ['category' => 'Hair salon', 'rows' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('rows');
    }

    public function test_a_staff_member_may_not_create_a_menu(): void
    {
        [$org] = $this->makeOrgWithOwner('alpha');
        $staff = User::create([
            'organization_id' => $org->id,
            'name' => 'Ruma',
            'email' => 'ruma@alpha.test',
            'password' => 'secret1234',
            'role' => 'staff',
            'status' => 'active',
        ]);

        $this->withToken($staff->createToken('api')->plainTextToken)
            ->postJson('/api/services/bulk', [
                'category' => 'Hair salon',
                'rows' => [['name' => 'Trim', 'duration' => 20, 'price' => 5]],
            ])
            ->assertForbidden();
    }

    /**
     * Both organizations post under the identical category label
     * ("Hair salon") — the exact condition that risks firstOrCreate()
     * matching across tenants if the lookup ever loses its organization_id
     * clause. Eloquent queries on ServiceCategory/Service are avoided here
     * because BelongsToOrganization's global scope would auto-filter them
     * to whichever tenant the *last* request bound, which would silently
     * mask a cross-tenant leak instead of proving its absence; the raw
     * assertDatabase* helpers and DB facade bypass that scope entirely.
     */
    public function test_one_salons_menu_never_lands_in_another(): void
    {
        [$orgA, $tokenA] = $this->makeOrgWithOwner('alpha');
        [$orgB, $tokenB] = $this->makeOrgWithOwner('bravo');

        $this->withToken($tokenA)->postJson('/api/services/bulk', [
            'category' => 'Hair salon',
            'rows' => [['name' => 'Trim', 'duration' => 20, 'price' => 5]],
        ])->assertCreated();

        // Sanctum's RequestGuard memoizes the resolved user within a single
        // process; several requests sharing one app instance (as here) need
        // the guard reset before the second token or it re-authenticates as
        // org A. See AppointmentCrudTest::test_index_date_filter_and_tenant_isolation
        // for the same idiom.
        $this->app['auth']->forgetGuards();
        $this->withToken($tokenB)->postJson('/api/services/bulk', [
            'category' => 'Hair salon',
            'rows' => [['name' => 'Blow Dry', 'duration' => 25, 'price' => 8]],
        ])->assertCreated();

        // firstOrCreate() must not have reused org A's category for org B.
        $this->assertDatabaseCount('service_categories', 2);
        $this->assertDatabaseHas('service_categories', ['organization_id' => $orgA->id, 'name' => 'Hair salon']);
        $this->assertDatabaseHas('service_categories', ['organization_id' => $orgB->id, 'name' => 'Hair salon']);

        $categoryA = DB::table('service_categories')->where('organization_id', $orgA->id)->value('id');
        $categoryB = DB::table('service_categories')->where('organization_id', $orgB->id)->value('id');
        $this->assertNotSame($categoryA, $categoryB);

        $this->assertDatabaseHas('services', [
            'organization_id' => $orgA->id,
            'category_id' => $categoryA,
            'name' => 'Trim',
        ]);
        $this->assertDatabaseHas('services', [
            'organization_id' => $orgB->id,
            'category_id' => $categoryB,
            'name' => 'Blow Dry',
        ]);

        $this->assertDatabaseMissing('services', ['organization_id' => $orgA->id, 'name' => 'Blow Dry']);
        $this->assertDatabaseMissing('services', ['organization_id' => $orgB->id, 'name' => 'Trim']);
    }
}
```

The test file also imports `Illuminate\Support\Facades\DB`, for the two
raw-table lookups in the cross-tenant test.

- [ ] **Step 2: Run them and watch them fail**

Run: `php artisan test --filter=BulkServiceTest`
Expected: FAIL — 405 Method Not Allowed on `/api/services/bulk`. (Not 404:
`apiResource('services')` already registers `services/{service}` for other
verbs, so the URI matches and Laravel reports the wrong method. The cause is
the same route shadowing Step 5 guards against.)

- [ ] **Step 3: Write the request**

Create `backend/app/Http/Requests/Service/BulkStoreServiceRequest.php`:

```php
<?php

namespace App\Http\Requests\Service;

use App\Models\Service;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A whole starter menu in one call, from the onboarding wizard.
 *
 * Rules mirror StoreServiceRequest per row so a menu can never contain a
 * service the single-create endpoint would have refused; the difference is
 * only the shape and the fact that errors are keyed by row index, which is
 * what lets the wizard highlight the offending line rather than saying
 * "something is wrong" about a list of eight.
 */
class BulkStoreServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Service::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', 'string', 'max:255'],
            'rows' => ['required', 'array', 'min:1', 'max:50'],
            'rows.*.name' => ['required', 'string', 'max:255'],
            'rows.*.duration' => ['required', 'integer', 'min:1'],
            'rows.*.price' => ['required', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rows.*.price.required' => 'Add a price for every service you ticked.',
            'rows.min' => 'Pick at least one service.',
        ];
    }
}
```

- [ ] **Step 4: Write the controller method**

Add to `backend/app/Http/Controllers/ServiceController.php`, after `store()`:

```php
    /**
     * Create a whole starter menu in one transaction, for the onboarding
     * wizard's second screen.
     *
     * The salon type becomes a category so the public page gets its
     * grouping without asking the owner a second question. firstOrCreate,
     * not create: an owner who walks the wizard twice — or backs up and
     * continues — must not end up with two "Hair salon" categories.
     */
    public function bulkStore(BulkStoreServiceRequest $request): JsonResponse
    {
        $data = $request->validated();
        $tenantId = app(CurrentTenant::class)->id();

        $services = DB::transaction(function () use ($data, $tenantId) {
            $category = ServiceCategory::firstOrCreate([
                'organization_id' => $tenantId,
                'name' => $data['category'],
            ]);

            return collect($data['rows'])->map(fn (array $row) => Service::create([
                'category_id' => $category->id,
                'name' => $row['name'],
                'duration' => $row['duration'],
                'price' => $row['price'],
                'status' => ServiceStatus::ACTIVE->value,
            ]))->all();
        });

        return ServiceResource::collection(
            Service::with('category')->whereIn('id', collect($services)->pluck('id'))->get()
        )->response()->setStatusCode(201);
    }
```

Add the imports at the top of the file:

```php
use App\Http\Requests\Service\BulkStoreServiceRequest;
use App\Models\ServiceCategory;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;
```

`Service` uses `BelongsToOrganization`, so `organization_id` is filled by the global scope on create — do not set it by hand. `ServiceCategory` creation is written with an explicit `organization_id` to match the uniqueness lookup.

- [ ] **Step 5: Register the route**

In `routes/api.php`, **above** `Route::apiResource('services', ServiceController::class);` — a literal segment declared after the resource would be swallowed by `services/{service}`:

```php
    // Declared before the resource: `services/bulk` must not be read as
    // `services/{service}` with an id of "bulk".
    Route::post('services/bulk', [ServiceController::class, 'bulkStore']);
```

- [ ] **Step 6: Run the tests and watch them pass**

Run: `php artisan test --filter=BulkServiceTest`
Expected: PASS, all seven tests — six on SQLite plus the rollback test, which
skips off MySQL and passes on the backend-mysql CI job.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Http/Requests/Service/BulkStoreServiceRequest.php backend/app/Http/Controllers/ServiceController.php backend/routes/api.php backend/tests/Feature/Onboarding/BulkServiceTest.php
git commit -m "feat: create a starter service menu in one request"
```

---

### Task 5: Add a staff member who has no email address

**Files:**
- Modify: `backend/app/Http/Requests/Staff/StoreStaffRequest.php`
- Modify: `backend/app/Http/Controllers/StaffController.php`
- Test: `backend/tests/Feature/Onboarding/StaffWithoutEmailTest.php`

**Interfaces:**
- Consumes: `PlanLimit::canAddStaff()` (unchanged).
- Produces: `POST /api/staff` accepts a missing or null `email` and mints `staff-{token}@{slug}.invalid`. Response shape is unchanged (`StaffResource`).

- [ ] **Step 1: Write the failing tests**

Create `backend/tests/Feature/Onboarding/StaffWithoutEmailTest.php`:

```php
<?php

namespace Tests\Feature\Onboarding;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StaffWithoutEmailTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Organization, 1: string}
     */
    private function makeOrgWithOwner(string $slug): array
    {
        $org = Organization::create([
            'uuid' => (string) Str::uuid(),
            'name' => ucfirst($slug),
            'slug' => $slug,
            'email' => "owner@{$slug}.test",
            'subscription_plan' => 'free',
            'status' => 'active',
        ]);

        $owner = User::create([
            'organization_id' => $org->id,
            'name' => "{$slug} owner",
            'email' => "owner@{$slug}.test",
            'password' => 'secret1234',
            'role' => 'owner',
            'status' => 'active',
        ]);

        return [$org, $owner->createToken('api')->plainTextToken];
    }

    public function test_a_staff_member_can_be_added_with_only_a_name_and_phone(): void
    {
        [$org, $token] = $this->makeOrgWithOwner('alpha');

        $response = $this->withToken($token)->postJson('/api/staff', [
            'name' => 'Ruma',
            'phone' => '01712345678',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'Ruma');

        $email = User::where('organization_id', $org->id)->where('role', 'staff')->value('email');
        $this->assertStringEndsWith('@alpha.invalid', $email);
        $this->assertStringStartsWith('staff-', $email);
    }

    public function test_two_such_staff_do_not_collide(): void
    {
        [$org, $token] = $this->makeOrgWithOwner('alpha');

        $this->withToken($token)->postJson('/api/staff', ['name' => 'Ruma'])->assertCreated();
        $this->withToken($token)->postJson('/api/staff', ['name' => 'Shila'])->assertCreated();

        $emails = User::where('organization_id', $org->id)->where('role', 'staff')->pluck('email');

        $this->assertCount(2, $emails->unique());
    }

    public function test_the_solo_owner_gets_a_staff_row_of_their_own_and_keeps_their_owner_row(): void
    {
        [$org, $token] = $this->makeOrgWithOwner('alpha');

        // What the wizard's "I work alone" button posts: the owner's name,
        // no email, because their real address is already on their owner row
        // and users.email is unique.
        $this->withToken($token)->postJson('/api/staff', ['name' => 'alpha owner'])->assertCreated();

        $this->assertSame(1, User::where('organization_id', $org->id)->where('role', 'staff')->count());

        $owner = User::where('organization_id', $org->id)->where('role', 'owner')->first();
        $this->assertSame('owner@alpha.test', $owner->email);
        // UserRole, not the string: User casts `role` to the backed enum, so
        // assertSame('owner', ...) could never pass. Matches RegisterTest.
        $this->assertSame(UserRole::OWNER, $owner->role);
    }

    public function test_a_real_email_is_still_honoured(): void
    {
        [, $token] = $this->makeOrgWithOwner('alpha');

        $this->withToken($token)
            ->postJson('/api/staff', ['name' => 'Ruma', 'email' => 'ruma@example.com'])
            ->assertCreated()
            ->assertJsonPath('data.email', 'ruma@example.com');
    }

    public function test_a_duplicate_real_email_is_still_refused(): void
    {
        [, $token] = $this->makeOrgWithOwner('alpha');

        $this->withToken($token)
            ->postJson('/api/staff', ['name' => 'Ruma', 'email' => 'ruma@example.com'])
            ->assertCreated();

        $this->withToken($token)
            ->postJson('/api/staff', ['name' => 'Shila', 'email' => 'ruma@example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }
}
```

- [ ] **Step 2: Run them and watch them fail**

Run: `php artisan test --filter=StaffWithoutEmailTest`
Expected: FAIL — the first two tests get 422 "The email field is required".

- [ ] **Step 3: Make the email optional**

In `backend/app/Http/Requests/Staff/StoreStaffRequest.php`, change the `email` rule from
`['required', 'email', 'max:255', 'unique:users,email']` to:

```php
            // Optional: a salon assistant known only by name and phone is the
            // common case, and requiring an address for them would either
            // block the wizard or invite junk. StaffController mints an
            // undeliverable placeholder when this is absent.
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
```

- [ ] **Step 4: Mint the placeholder**

In `backend/app/Http/Controllers/StaffController.php`, inside `store()`, replace
`'email' => $data['email'],` with `'email' => $data['email'] ?? $this->placeholderEmail(),`
and add this method to the class:

```php
    /**
     * An address for a staff member who has none.
     *
     * `.invalid` is reserved by RFC 2606 and is guaranteed never to resolve,
     * so a placeholder can never deliver mail to a real stranger who happens
     * to own the domain we would otherwise have invented. The row still
     * cannot be signed into: the password is the random one store() already
     * generates and is never shown to anyone, and no verification mail is
     * sent.
     */
    protected function placeholderEmail(): string
    {
        $slug = app(CurrentTenant::class)->get()?->slug ?? 'salon';

        return 'staff-'.Str::lower(Str::random(10)).'@'.$slug.'.invalid';
    }
```

`Str` and `CurrentTenant` are already imported in this file.

- [ ] **Step 5: Run the tests and watch them pass**

Run: `php artisan test --filter=StaffWithoutEmailTest`
Expected: PASS, all four tests.

- [ ] **Step 6: Run the whole backend suite**

Run: `php artisan test`
Expected: PASS. `StaffCrudTest` covers the previous required-email behaviour — if a test there asserts a 422 for a missing email, update it to assert the placeholder instead, and say so in the commit message.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Http/Requests/Staff/StoreStaffRequest.php backend/app/Http/Controllers/StaffController.php backend/tests
git commit -m "feat: let a salon add staff who have no email address"
```

---

### Task 6: Teach the SPA about onboarding

**Files:**
- Create: `frontend/src/stores/onboarding.js`
- Create: `frontend/src/stores/onboarding.spec.js`
- Test: `frontend/src/stores/onboarding.spec.js`

**Interfaces:**
- Consumes: `GET /api/onboarding/status`, `POST /api/onboarding/complete` (Task 2).
- Produces: `useOnboardingStore()` exposing
  `status` (ref, the raw payload or null), `loading`, `steps` (computed object), `nextStep` (computed string), `branchId` (computed), `isComplete` (computed bool), `requiredDone` (computed bool — branch, services and staff all true), `fetchStatus()`, `markStepDone(key)`, `complete()`.

- [ ] **Step 1: Write the failing test**

Create `frontend/src/stores/onboarding.spec.js`:

```js
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal()
  return { ...actual, default: { get: vi.fn(), post: vi.fn() } }
})

import api from '@/lib/api'
import { useOnboardingStore } from './onboarding'

const payload = (overrides = {}) => ({
  data: {
    data: {
      completed_at: null,
      branch_id: 7,
      steps: { branch: false, services: false, staff: false, look: false },
      next_step: 'branch',
      ...overrides,
    },
  },
})

describe('useOnboardingStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(api.get).mockReset()
    vi.mocked(api.post).mockReset()
  })

  it('reports nothing done before the status is fetched', () => {
    const store = useOnboardingStore()

    expect(store.nextStep).toBe('branch')
    expect(store.requiredDone).toBe(false)
    expect(store.isComplete).toBe(false)
  })

  it('exposes the fetched steps', async () => {
    vi.mocked(api.get).mockResolvedValue(
      payload({ steps: { branch: true, services: true, staff: false, look: false }, next_step: 'staff' }),
    )
    const store = useOnboardingStore()

    await store.fetchStatus()

    expect(api.get).toHaveBeenCalledWith('/onboarding/status')
    expect(store.steps.branch).toBe(true)
    expect(store.nextStep).toBe('staff')
    expect(store.branchId).toBe(7)
    expect(store.requiredDone).toBe(false)
  })

  it('treats a salon with branch, services and staff as ready even without the look step', async () => {
    vi.mocked(api.get).mockResolvedValue(
      payload({ steps: { branch: true, services: true, staff: true, look: false }, next_step: 'look' }),
    )
    const store = useOnboardingStore()

    await store.fetchStatus()

    expect(store.requiredDone).toBe(true)
    expect(store.isComplete).toBe(false)
  })

  it('marks a step done locally so the wizard advances without a round trip', async () => {
    vi.mocked(api.get).mockResolvedValue(payload())
    const store = useOnboardingStore()
    await store.fetchStatus()

    store.markStepDone('branch')

    expect(store.steps.branch).toBe(true)
    expect(store.nextStep).toBe('services')
  })

  it('records completion from the server response', async () => {
    vi.mocked(api.post).mockResolvedValue(payload({ completed_at: '2026-08-06T10:00:00+00:00' }))
    const store = useOnboardingStore()

    await store.complete()

    expect(api.post).toHaveBeenCalledWith('/onboarding/complete')
    expect(store.isComplete).toBe(true)
  })
})
```

- [ ] **Step 2: Run it and watch it fail**

Run: `npm run test:unit -- onboarding`
Expected: FAIL — cannot resolve `./onboarding`.

- [ ] **Step 3: Write the store**

Create `frontend/src/stores/onboarding.js`:

```js
import { ref, computed } from 'vue'
import { defineStore } from 'pinia'
import api from '@/lib/api'

// The wizard's screen order. `look` is last and optional: the public page
// renders with defaults, so nothing about it stops a salon taking bookings.
export const STEPS = ['branch', 'services', 'staff', 'look']
export const REQUIRED_STEPS = ['branch', 'services', 'staff']

const NOTHING_DONE = { branch: false, services: false, staff: false, look: false }

export const useOnboardingStore = defineStore('onboarding', () => {
  const status = ref(null)
  const loading = ref(false)

  const steps = computed(() => status.value?.steps ?? { ...NOTHING_DONE })
  const branchId = computed(() => status.value?.branch_id ?? null)
  const isComplete = computed(() => !!status.value?.completed_at)

  // Whether the salon can actually take a booking. Distinct from
  // isComplete, which only says the owner has stopped being asked.
  const requiredDone = computed(() => REQUIRED_STEPS.every((key) => steps.value[key]))

  const nextStep = computed(() => STEPS.find((key) => !steps.value[key]) ?? 'done')

  async function fetchStatus() {
    loading.value = true
    try {
      const { data } = await api.get('/onboarding/status')
      status.value = data.data
      return status.value
    } finally {
      loading.value = false
    }
  }

  /**
   * Flip a step locally after its screen saved successfully, so the wizard
   * advances immediately. The server is still the authority — the next
   * fetchStatus() overwrites this — but re-fetching between every screen
   * would put a spinner between the owner and their next question.
   */
  function markStepDone(key) {
    if (!status.value) {
      status.value = { completed_at: null, branch_id: null, steps: { ...NOTHING_DONE }, next_step: 'branch' }
    }
    status.value.steps = { ...status.value.steps, [key]: true }
  }

  async function complete() {
    const { data } = await api.post('/onboarding/complete')
    status.value = data.data
    return status.value
  }

  return {
    status,
    loading,
    steps,
    branchId,
    isComplete,
    requiredDone,
    nextStep,
    fetchStatus,
    markStepDone,
    complete,
  }
})
```

- [ ] **Step 4: Run the tests and watch them pass**

Run: `npm run test:unit -- onboarding`
Expected: PASS, five tests.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/stores/onboarding.js frontend/src/stores/onboarding.spec.js
git commit -m "feat: track onboarding progress in the SPA"
```

---

### Task 7: Send a new owner to the wizard

**Files:**
- Modify: `frontend/src/router/index.js`
- Create: `frontend/src/router/guard.spec.js`
- Create: `frontend/src/layouts/OnboardingLayout.vue`
- Create: `frontend/src/views/onboarding/OnboardingView.vue`

**Interfaces:**
- Consumes: `useOnboardingStore` (Task 6), `authStore.organization.onboarding_completed_at` (Task 1).
- Produces: route `{ path: '/onboarding', name: 'onboarding' }`, and an exported guard predicate
  `needsOnboarding(authStore, to): boolean` used by `router.beforeEach`.
- `OnboardingView.vue` renders a placeholder in this task; Tasks 8–12 fill in the screens.

- [ ] **Step 1: Write the failing test**

Create `frontend/src/router/guard.spec.js`:

```js
import { describe, it, expect } from 'vitest'
import { needsOnboarding } from './index'

const owner = (completedAt) => ({
  isAuthenticated: true,
  role: 'owner',
  organization: { onboarding_completed_at: completedAt },
})

const dashboard = { name: 'dashboard', path: '/dashboard', meta: { requiresAuth: true } }

describe('needsOnboarding', () => {
  it('sends an owner who has never finished setup to the wizard', () => {
    expect(needsOnboarding(owner(null), dashboard)).toBe(true)
  })

  it('leaves an owner who has finished alone', () => {
    expect(needsOnboarding(owner('2026-08-06T10:00:00+00:00'), dashboard)).toBe(false)
  })

  it('never diverts a manager or a staff member', () => {
    expect(needsOnboarding({ ...owner(null), role: 'manager' }, dashboard)).toBe(false)
    expect(needsOnboarding({ ...owner(null), role: 'staff' }, dashboard)).toBe(false)
  })

  it('does not divert the wizard route itself, or it would loop', () => {
    expect(needsOnboarding(owner(null), { name: 'onboarding', path: '/onboarding', meta: { requiresAuth: true } }))
      .toBe(false)
  })

  it('leaves public routes alone', () => {
    expect(needsOnboarding(owner(null), { name: 'salon-site', path: '/salon/alpha', meta: {} })).toBe(false)
  })

  it('waits until the organization has loaded rather than guessing', () => {
    expect(needsOnboarding({ isAuthenticated: true, role: 'owner', organization: null }, dashboard)).toBe(false)
  })
})
```

- [ ] **Step 2: Run it and watch it fail**

Run: `npm run test:unit -- guard`
Expected: FAIL — `needsOnboarding` is not exported from `./index`.

- [ ] **Step 3: Add the layout and view shell**

Create `frontend/src/layouts/OnboardingLayout.vue`:

```vue
<script setup>
import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'

const props = defineProps({
  step: { type: Number, required: true },
  total: { type: Number, default: 4 },
  title: { type: String, required: true },
  subtitle: { type: String, default: '' },
  canSkip: { type: Boolean, default: true },
  canGoBack: { type: Boolean, default: true },
})

defineEmits(['back', 'skip'])

const authStore = useAuthStore()
const salonName = computed(() => authStore.organization?.name ?? 'your salon')
const dots = computed(() => Array.from({ length: props.total }, (_, i) => i + 1))
</script>

<template>
  <div class="min-h-screen bg-slate-50">
    <header class="border-b border-slate-200 bg-white">
      <div class="mx-auto flex max-w-2xl items-center justify-between px-4 py-3">
        <button
          v-if="canGoBack"
          type="button"
          class="text-sm font-medium text-slate-500 transition hover:text-slate-900"
          @click="$emit('back')"
        >
          &larr; Back
        </button>
        <span v-else class="text-sm font-semibold text-slate-900">{{ salonName }}</span>

        <div class="flex items-center gap-1.5" aria-hidden="true">
          <span
            v-for="dot in dots"
            :key="dot"
            class="h-2 rounded-full transition-all"
            :class="dot === step ? 'w-6 bg-indigo-600' : dot < step ? 'w-2 bg-indigo-300' : 'w-2 bg-slate-200'"
          />
        </div>

        <button
          v-if="canSkip"
          type="button"
          class="text-sm font-medium text-slate-500 transition hover:text-slate-900"
          @click="$emit('skip')"
        >
          Skip for now
        </button>
        <span v-else class="w-20" />
      </div>
    </header>

    <main class="mx-auto max-w-2xl px-4 pb-32 pt-8">
      <p class="text-sm font-medium text-indigo-600">Step {{ step }} of {{ total }}</p>
      <h1 class="mt-1 font-[Fraunces_Variable,serif] text-2xl font-semibold text-slate-900 sm:text-3xl">
        {{ title }}
      </h1>
      <p v-if="subtitle" class="mt-2 text-slate-600">{{ subtitle }}</p>

      <div class="mt-6">
        <slot />
      </div>
    </main>

    <!-- Sticky, because on a phone the primary action must never be below
         the fold of a form the owner is still filling in. -->
    <div class="fixed inset-x-0 bottom-0 border-t border-slate-200 bg-white/95 backdrop-blur">
      <div class="mx-auto max-w-2xl px-4 py-3">
        <slot name="action" />
      </div>
    </div>
  </div>
</template>
```

Create `frontend/src/views/onboarding/OnboardingView.vue` — the step host. It owns which screen shows and nothing else:

```vue
<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useOnboardingStore, STEPS } from '@/stores/onboarding'

const router = useRouter()
const onboarding = useOnboardingStore()

// 0..3 map to STEPS; 4 is the success screen.
const index = ref(0)
const ready = ref(false)
const current = computed(() => (index.value < STEPS.length ? STEPS[index.value] : 'done'))

onMounted(async () => {
  try {
    await onboarding.fetchStatus()
    const next = onboarding.nextStep
    // Resume where they left off. 'done' means every step is already
    // satisfied, so go straight to the payoff screen.
    index.value = next === 'done' ? STEPS.length : STEPS.indexOf(next)
  } finally {
    ready.value = true
  }
})

function advance(stepKey) {
  onboarding.markStepDone(stepKey)
  index.value = Math.min(index.value + 1, STEPS.length)
}

function skip() {
  index.value = Math.min(index.value + 1, STEPS.length)
}

function back() {
  if (index.value === 0) {
    leave()
    return
  }
  index.value -= 1
}

// Leaving before the end is allowed by design — the dashboard card picks
// up whatever is unfinished.
function leave() {
  router.push('/dashboard')
}

async function finish() {
  await onboarding.complete()
  router.push('/dashboard')
}
</script>

<template>
  <div v-if="!ready" class="grid min-h-screen place-items-center text-slate-500">Loading…</div>
  <template v-else>
    <p class="p-8 text-slate-500">Step: {{ current }}</p>
    <!-- Tasks 8-12 replace this with the real screens. -->
    <div class="flex gap-3 px-8">
      <button class="rounded-lg bg-indigo-600 px-4 py-2 text-white" @click="advance(current)">Next</button>
      <button class="rounded-lg px-4 py-2 text-slate-500" @click="skip">Skip</button>
      <button class="rounded-lg px-4 py-2 text-slate-500" @click="back">Back</button>
      <button class="rounded-lg px-4 py-2 text-slate-500" @click="finish">Finish</button>
    </div>
  </template>
</template>
```

- [ ] **Step 4: Add the route and the guard**

In `frontend/src/router/index.js`, add the route **before** the `DashboardLayout` record (it renders its own full-page shell, not inside the dashboard):

```js
    {
      path: '/onboarding',
      name: 'onboarding',
      component: () => import('@/views/onboarding/OnboardingView.vue'),
      meta: { requiresAuth: true, roles: ['owner'] },
    },
```

Above `const router = createRouter(...)`, export the predicate:

```js
/**
 * Whether this navigation should be diverted into first-run setup.
 *
 * Owner-only: a manager or staff member joins a salon someone else has
 * already configured. Exported so it can be tested without standing up a
 * router — the rule is the part worth testing, not vue-router.
 */
export function needsOnboarding(authStore, to) {
  if (!to.meta?.requiresAuth) return false
  if (to.name === 'onboarding') return false
  if (!authStore.isAuthenticated || authStore.role !== 'owner') return false
  // The organization is not loaded yet on a cold start; the guard fetches
  // it just above, and a null here means "don't know", not "not onboarded".
  if (!authStore.organization) return false

  return !authStore.organization.onboarding_completed_at
}
```

In `router.beforeEach`, insert the check **after** the `fetchMe()` block (which is what populates `authStore.organization`) and **before** the `meta.roles` check:

```js
  if (needsOnboarding(authStore, to)) {
    return '/onboarding'
  }
```

The host's resume behaviour is tested in Task 12, once all five screens exist to stub.

- [ ] **Step 5: Run the tests and watch them pass**

Run: `npm run test:unit`
Expected: PASS — the six guard tests plus the existing suites.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/router frontend/src/layouts/OnboardingLayout.vue frontend/src/views/onboarding
git commit -m "feat: route a new owner into first-run setup"
```

---

### Task 8: Screen 1 — where is your salon?

**Files:**
- Create: `frontend/src/views/onboarding/StepBranch.vue`
- Modify: `frontend/src/views/onboarding/OnboardingView.vue`

**Interfaces:**
- Consumes: `onboarding.branchId` (Task 6), `PUT /api/branches/{id}`.
- Produces: component `StepBranch` with props `{ branchId: Number }` and events `@done` (saved) and `@skip`.

- [ ] **Step 1: Build the screen**

Create `frontend/src/views/onboarding/StepBranch.vue`:

```vue
<script setup>
import { ref, onMounted, computed } from 'vue'
import api from '@/lib/api'
import { parseApiError } from '@/lib/errors'
import { useAuthStore } from '@/stores/auth'
import OnboardingLayout from '@/layouts/OnboardingLayout.vue'

const props = defineProps({ branchId: { type: Number, default: null } })
const emit = defineEmits(['done', 'skip', 'back'])

const authStore = useAuthStore()

// Lowercase three-letter keys, exactly what SlotGenerator reads out of
// branches.opening_hours_json.
const DAYS = [
  { key: 'mon', label: 'Monday' },
  { key: 'tue', label: 'Tuesday' },
  { key: 'wed', label: 'Wednesday' },
  { key: 'thu', label: 'Thursday' },
  { key: 'fri', label: 'Friday' },
  { key: 'sat', label: 'Saturday' },
  { key: 'sun', label: 'Sunday' },
]

const form = ref({ address: '', city: '', phone: '' })
const hours = ref(
  Object.fromEntries(
    DAYS.map((d) => [d.key, { open: d.key === 'sun' ? false : true, from: '09:00', to: '18:00' }]),
  ),
)
const saving = ref(false)
const error = ref('')
const fieldErrors = ref({})

onMounted(async () => {
  form.value.phone = authStore.organization?.phone ?? ''
  if (!props.branchId) return
  try {
    const { data } = await api.get(`/branches/${props.branchId}`)
    const branch = data.data
    form.value.address = branch.address ?? ''
    form.value.city = branch.city ?? ''
    form.value.phone = branch.phone ?? form.value.phone
    for (const day of DAYS) {
      const stored = branch.opening_hours_json?.[day.key]
      hours.value[day.key] = stored
        ? { open: true, from: stored[0], to: stored[1] }
        : { open: false, from: '09:00', to: '18:00' }
    }
  } catch {
    // A branch we cannot read is not worth blocking setup over — the
    // defaults above are the same ones registration wrote.
  }
})

// One tap to say "we open the same time every day": copy Monday down onto
// every day that is open. Days marked closed stay closed.
function copyMondayDown() {
  const monday = hours.value.mon
  for (const day of DAYS) {
    if (day.key === 'mon') continue
    if (!hours.value[day.key].open) continue
    hours.value[day.key] = { ...hours.value[day.key], from: monday.from, to: monday.to }
  }
}

const canSave = computed(() => form.value.address.trim().length > 0)

async function save() {
  if (!canSave.value || !props.branchId) return
  saving.value = true
  error.value = ''
  fieldErrors.value = {}
  try {
    await api.put(`/branches/${props.branchId}`, {
      address: form.value.address.trim(),
      city: form.value.city.trim() || null,
      phone: form.value.phone.trim() || null,
      opening_hours_json: Object.fromEntries(
        DAYS.map(({ key }) => [key, hours.value[key].open ? [hours.value[key].from, hours.value[key].to] : null]),
      ),
    })
    emit('done')
  } catch (err) {
    const parsed = parseApiError(err)
    error.value = parsed.message
    fieldErrors.value = parsed.errors ?? {}
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <OnboardingLayout
    :step="1"
    title="Where is your salon?"
    subtitle="Four quick steps. About 3 minutes. Customers see this address on your booking page."
    @skip="emit('skip')"
    @back="emit('back')"
  >
    <div class="space-y-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
      <div>
        <label class="mb-1 block text-sm font-medium text-slate-700">Street address</label>
        <input
          v-model="form.address"
          type="text"
          placeholder="12 Green Road, Dhanmondi"
          class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
        />
        <p v-if="fieldErrors.address" class="mt-1 text-sm text-rose-600">{{ fieldErrors.address[0] }}</p>
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">City</label>
          <input
            v-model="form.city"
            type="text"
            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
          />
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">Phone</label>
          <input
            v-model="form.phone"
            type="tel"
            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
          />
        </div>
      </div>
    </div>

    <div class="mt-5 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
      <div class="flex items-center justify-between">
        <h2 class="font-semibold text-slate-900">When are you open?</h2>
        <button type="button" class="text-sm font-medium text-indigo-600" @click="copyMondayDown">
          Same time every day
        </button>
      </div>

      <ul class="mt-4 divide-y divide-slate-100">
        <li v-for="day in DAYS" :key="day.key" class="flex flex-wrap items-center gap-3 py-2.5">
          <label class="flex min-w-32 items-center gap-2">
            <input v-model="hours[day.key].open" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-indigo-600" />
            <span class="text-sm font-medium text-slate-700">{{ day.label }}</span>
          </label>
          <template v-if="hours[day.key].open">
            <input v-model="hours[day.key].from" type="time" class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm" />
            <span class="text-slate-400">to</span>
            <input v-model="hours[day.key].to" type="time" class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm" />
          </template>
          <span v-else class="text-sm text-slate-400">Closed</span>
        </li>
      </ul>
    </div>

    <p v-if="error" class="mt-4 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ error }}</p>

    <template #action>
      <button
        type="button"
        :disabled="!canSave || saving"
        class="w-full rounded-xl bg-indigo-600 px-4 py-3 font-semibold text-white shadow-sm transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:bg-slate-300"
        @click="save"
      >
        {{ saving ? 'Saving…' : 'Continue' }}
      </button>
      <p v-if="!canSave" class="mt-2 text-center text-sm text-slate-500">Add your address to continue.</p>
    </template>
  </OnboardingLayout>
</template>
```

- [ ] **Step 2: Wire it into the host**

In `OnboardingView.vue`, import it and replace the placeholder block for `current === 'branch'`:

```vue
<StepBranch
  v-if="current === 'branch'"
  :branch-id="onboarding.branchId"
  @done="advance('branch')"
  @skip="skip"
  @back="back"
/>
```

Add `import StepBranch from './StepBranch.vue'` to the script block.

- [ ] **Step 3: Write the screen's tests**

This step was missing from the plan as first written, and the screen shipped
untested until a follow-up round added it. Create
`frontend/src/views/onboarding/StepBranch.spec.js`, mounting the component
with `OnboardingLayout` stubbed and `@/lib/api` mocked via `importOriginal`.
Six tests, following the house pattern in `src/stores/onboarding.spec.js`:

1. **Hydration** — `GET /branches/{id}` returns an address, a city, and an
   `opening_hours_json` whose Monday is `["09:00","18:00"]` and whose Friday
   is `null`; assert the fields fill, Monday shows open with those times, and
   Friday shows Closed. Use a non-Sunday day for the closed case, so the
   assertion cannot pass on the component's own Sunday default.
2. **Hydration failure does not block setup** — `GET` rejects; the screen
   still renders, shows no error, and keeps the registration defaults.
3. **"Same time every day"** — after editing Monday and closing one day,
   every open day carries Monday's times and the closed day is untouched.
   Assert every open day, not one.
4. **The saved payload** — the `PUT` body carries the trimmed address, `null`
   for a blank city and phone, an `[from, to]` pair per open day, and `null`
   for the closed day. This is the assertion that matters: a pair sent for a
   closed day would let `SlotGenerator` take bookings on a day the salon is
   shut. Then `done` is emitted.
5. **Continue disabled without an address**, and clicking it in that state
   sends no request — the disabled attribute alone does not prove the guard
   inside `save()`.
6. **A rejected save reads as a sentence** — a 422 keyed `address` shows the
   human message, does not emit `done`, leaves the button usable again, and
   never renders the string `opening_hours_json`.

Prove tests 3, 4 and 6 by deliberate failure: drop `copyMondayDown`'s `open`
check, send a pair for closed days, delete the `finally` that resets
`saving`. Each must fail, then pass once restored.

- [ ] **Step 4: Check it by hand**

Run `npm run dev` (and the backend), register a fresh salon, and confirm: the wizard opens on this screen, "Same time every day" copies Monday's times onto Tue–Sat and leaves Sunday closed, Continue is disabled until an address is typed, and after saving the branch row has the address and the seven-key hours object.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/views/onboarding
git commit -m "feat: ask a new salon for its address and opening hours"
git commit -m "test: cover StepBranch hydration, hours shortcut, save payload, and error handling"
```

---

### Task 9: Screen 2 — what do you offer?

**Files:**
- Create: `frontend/src/views/onboarding/StepServices.vue`
- Modify: `frontend/src/views/onboarding/OnboardingView.vue`

**Interfaces:**
- Consumes: `GET /api/service-presets` (Task 3), `POST /api/services/bulk` (Task 4).
- Produces: component `StepServices` emitting `@done` with no payload, plus `@skip` and `@back`.

- [ ] **Step 1: Build the screen**

Create `frontend/src/views/onboarding/StepServices.vue`:

```vue
<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '@/lib/api'
import { parseApiError } from '@/lib/errors'
import { useAuthStore } from '@/stores/auth'
import OnboardingLayout from '@/layouts/OnboardingLayout.vue'

const emit = defineEmits(['done', 'skip', 'back'])
const authStore = useAuthStore()

const presets = ref([])
const chosenType = ref(null)
const rows = ref([])
const saving = ref(false)
const error = ref('')
const rowErrors = ref({})

const currency = computed(() => authStore.organization?.currency || 'USD')

onMounted(async () => {
  try {
    const { data } = await api.get('/service-presets')
    presets.value = data.data
  } catch (err) {
    error.value = parseApiError(err).message
  }
})

// Picking a type fills the list with that menu, everything ticked. The
// owner unticks what they do not do — reading and unticking is far less
// work for a non-technical user than composing a menu from nothing.
function chooseType(type) {
  chosenType.value = type
  rows.value = type.services.map((service) => ({
    name: service.name,
    duration: service.duration,
    price: '',
    ticked: true,
  }))
  rowErrors.value = {}
}

function addOwnRow() {
  rows.value.push({ name: '', duration: 30, price: '', ticked: true })
}

// A price the server will accept: present, a finite number, never negative
// (server rule is `numeric|min:0`). Checked client-side so a bad value is
// caught before the round trip, not after.
function isValidPrice(price) {
  const n = Number(price)
  return String(price).trim() !== '' && Number.isFinite(n) && n >= 0
}

// A duration the server will accept: a whole number of minutes, at least
// one (server rule is `integer|min:1`). Blanking the field leaves an empty
// string; Vue's `.number` modifier can't parse that so it stays a string,
// and `Number('')` is 0 — which must read as invalid, not "free".
function isValidDuration(duration) {
  const n = Number(duration)
  return Number.isInteger(n) && n >= 1
}

const ticked = computed(() => rows.value.filter((row) => row.ticked))
const canSave = computed(
  () =>
    ticked.value.length > 0 &&
    ticked.value.every((row) => row.name.trim() && isValidDuration(row.duration) && isValidPrice(row.price)),
)

const blockingReason = computed(() => {
  if (!chosenType.value) return 'Pick your salon type to continue.'
  if (ticked.value.length === 0) return 'Tick at least one service.'
  if (ticked.value.some((row) => !isValidDuration(row.duration))) {
    return 'Set a duration of at least 1 minute for every service you ticked.'
  }
  if (ticked.value.some((row) => String(row.price).trim() === '')) {
    return 'Add a price for every service you ticked.'
  }
  if (ticked.value.some((row) => !isValidPrice(row.price))) {
    return 'Enter a price of 0 or more for every service you ticked.'
  }
  // Only a blank service name can still fail canSave at this point — the
  // catch-all keeps Continue from ever being disabled with nothing said.
  if (!canSave.value) return 'Add a price for every service you ticked.'
  return ''
})

async function save() {
  if (!canSave.value) return
  saving.value = true
  error.value = ''
  rowErrors.value = {}
  // Build the posted list and, in the same pass, a map from each posted
  // row's position back to its position in the full `rows` array (the one
  // the template renders and `rowErrors` is keyed against). Ticking a row
  // out shifts every later row's position in the posted array but not in
  // `rows`, so the two only agree by coincidence — never assume they match.
  const postedRowIndexes = []
  const postedRows = []
  rows.value.forEach((row, index) => {
    if (!row.ticked) return
    postedRowIndexes.push(index)
    postedRows.push({
      name: row.name.trim(),
      duration: Number(row.duration),
      price: Number(row.price),
    })
  })
  try {
    await api.post('/services/bulk', {
      category: chosenType.value.label,
      rows: postedRows,
    })
    emit('done')
  } catch (err) {
    const parsed = parseApiError(err)
    error.value = parsed.message
    // Errors arrive keyed `rows.<postedIndex>.<field>`, e.g. `rows.1.price`.
    // Translate the posted index back through postedRowIndexes to the row's
    // real position before highlighting it.
    for (const [key, messages] of Object.entries(parsed.errors ?? {})) {
      const match = key.match(/^rows\.(\d+)\./)
      if (!match) continue
      const rowIndex = postedRowIndexes[Number(match[1])]
      if (rowIndex !== undefined) rowErrors.value[rowIndex] = messages[0]
    }
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <OnboardingLayout
    :step="2"
    title="What do you offer?"
    subtitle="Pick your salon type, then set your prices. You can change all of this later."
    @skip="emit('skip')"
    @back="emit('back')"
  >
    <div v-if="!chosenType" class="grid gap-3 sm:grid-cols-2">
      <button
        v-for="type in presets"
        :key="type.key"
        type="button"
        class="rounded-2xl bg-white p-5 text-left shadow-sm ring-1 ring-slate-200 transition hover:ring-indigo-400"
        @click="chooseType(type)"
      >
        <span class="block font-semibold text-slate-900">{{ type.label }}</span>
        <span class="mt-1 block text-sm text-slate-500">{{ type.services.length }} popular services ready to go</span>
      </button>
    </div>

    <div v-else class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
      <div class="flex items-center justify-between">
        <h2 class="font-semibold text-slate-900">{{ chosenType.label }}</h2>
        <button type="button" class="text-sm font-medium text-indigo-600" @click="chosenType = null">Change</button>
      </div>

      <ul class="mt-4 space-y-3">
        <li
          v-for="(row, i) in rows"
          :key="i"
          class="rounded-xl p-3 ring-1"
          :class="rowErrors[i] ? 'ring-rose-300 bg-rose-50' : 'ring-slate-200'"
        >
          <div class="flex items-center gap-3">
            <input v-model="row.ticked" type="checkbox" class="h-5 w-5 rounded border-slate-300 text-indigo-600" />
            <input
              v-model="row.name"
              type="text"
              placeholder="Service name"
              class="min-w-0 flex-1 rounded-lg border border-slate-300 px-3 py-2 text-slate-900 outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
            />
          </div>
          <div v-if="row.ticked" class="mt-2 flex items-center gap-3 pl-8">
            <label class="flex items-center gap-1.5 text-sm text-slate-600">
              <input v-model.number="row.duration" type="number" min="5" step="5" class="w-20 rounded-lg border border-slate-300 px-2 py-1.5" />
              min
            </label>
            <label class="flex items-center gap-1.5 text-sm text-slate-600">
              <span>{{ currency }}</span>
              <input
                v-model="row.price"
                type="number"
                min="0"
                step="any"
                placeholder="Price"
                class="w-28 rounded-lg border border-slate-300 px-2 py-1.5"
              />
            </label>
          </div>
          <p v-if="rowErrors[i]" class="mt-1 pl-8 text-sm text-rose-600">{{ rowErrors[i] }}</p>
        </li>
      </ul>

      <button type="button" class="mt-4 text-sm font-medium text-indigo-600" @click="addOwnRow">
        + Add your own
      </button>
    </div>

    <p v-if="error" class="mt-4 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ error }}</p>

    <template #action>
      <button
        type="button"
        :disabled="!canSave || saving"
        class="w-full rounded-xl bg-indigo-600 px-4 py-3 font-semibold text-white shadow-sm transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:bg-slate-300"
        @click="save"
      >
        {{ saving ? 'Saving…' : 'Continue' }}
      </button>
      <p v-if="blockingReason" class="mt-2 text-center text-sm text-slate-500">{{ blockingReason }}</p>
    </template>
  </OnboardingLayout>
</template>
```

- [ ] **Step 2: Wire it into the host**

In `OnboardingView.vue`, add the import and the block:

```vue
<StepServices v-else-if="current === 'services'" @done="advance('services')" @skip="skip" @back="back" />
```

- [ ] **Step 3: Test the price gate**

Create `frontend/src/views/onboarding/StepServices.spec.js`:

```js
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal()
  return { ...actual, default: { get: vi.fn(), post: vi.fn() } }
})

import api from '@/lib/api'
import StepServices from './StepServices.vue'

const PRESETS = {
  data: {
    data: [
      {
        key: 'barber',
        label: 'Barber',
        services: [
          { name: 'Hair cut', duration: 30 },
          { name: 'Beard trim', duration: 15 },
        ],
      },
    ],
  },
}

const mountStep = () =>
  mount(StepServices, {
    global: { stubs: { OnboardingLayout: { template: '<div><slot /><slot name="action" /></div>' } } },
  })

describe('StepServices', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(api.get).mockReset().mockResolvedValue(PRESETS)
    vi.mocked(api.post).mockReset().mockResolvedValue({ data: { data: [] } })
  })

  it('keeps Continue disabled while a ticked row has no price', async () => {
    const wrapper = mountStep()
    await flushPromises()

    await wrapper.findAll('button').find((b) => b.text().includes('Barber')).trigger('click')

    const continueButton = () => wrapper.findAll('button').find((b) => b.text().includes('Continue'))
    expect(continueButton().attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('Add a price for every service you ticked')
  })

  it('enables Continue once every ticked row is priced', async () => {
    const wrapper = mountStep()
    await flushPromises()
    await wrapper.findAll('button').find((b) => b.text().includes('Barber')).trigger('click')

    const priceInputs = wrapper.findAll('input[placeholder="Price"]')
    await priceInputs[0].setValue('12')
    await priceInputs[1].setValue('5')

    const continueButton = wrapper.findAll('button').find((b) => b.text().includes('Continue'))
    expect(continueButton.attributes('disabled')).toBeUndefined()
  })

  it('ignores the price of a row the owner unticked', async () => {
    const wrapper = mountStep()
    await flushPromises()
    await wrapper.findAll('button').find((b) => b.text().includes('Barber')).trigger('click')

    await wrapper.findAll('input[type="checkbox"]')[1].setValue(false)
    await wrapper.findAll('input[placeholder="Price"]')[0].setValue('12')

    const continueButton = wrapper.findAll('button').find((b) => b.text().includes('Continue'))
    expect(continueButton.attributes('disabled')).toBeUndefined()
  })
})
```

Run: `npm run test:unit -- StepServices`
Expected: PASS, three tests.

- [ ] **Step 4: Check it by hand**

Run the app. Confirm: choosing "Barber" fills eight ticked rows and saving creates one category named "Barber" holding exactly the ticked services.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/views/onboarding
git commit -m "feat: build a salon's menu from a starter list"
```

---

### Task 10: Screen 3 — who works here?

**Files:**
- Create: `frontend/src/views/onboarding/StepStaff.vue`
- Modify: `frontend/src/views/onboarding/OnboardingView.vue`

**Interfaces:**
- Consumes: `GET /api/services`, `POST /api/staff` (Task 5), `GET /api/branches/{id}` for hours.
- Produces: component `StepStaff` emitting `@done`, `@skip`, `@back`, with prop `{ branchId: Number }`.

- [ ] **Step 1: Build the screen**

Create `frontend/src/views/onboarding/StepStaff.vue`:

```vue
<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '@/lib/api'
import { parseApiError } from '@/lib/errors'
import { useAuthStore } from '@/stores/auth'
import OnboardingLayout from '@/layouts/OnboardingLayout.vue'

const props = defineProps({ branchId: { type: Number, default: null } })
const emit = defineEmits(['done', 'skip', 'back'])

const authStore = useAuthStore()

// Mirrors PlanLimit::FREE_MAX_STAFF. The server is the gate; this only
// keeps the owner from typing an eleventh row it would refuse.
const FREE_MAX_STAFF = 10

// working_days_json is 1..7 (Monday..Sunday); the branch stores hours by
// three-letter key. This maps one onto the other.
const DAY_NUMBERS = { mon: 1, tue: 2, wed: 3, thu: 4, fri: 5, sat: 6, sun: 7 }

const mode = ref(null) // null | 'solo' | 'team'
const services = ref([])
const branchHours = ref(null)
const people = ref([])
const saving = ref(false)
const error = ref('')

onMounted(async () => {
  try {
    const [serviceRes, branchRes] = await Promise.all([
      api.get('/services'),
      props.branchId ? api.get(`/branches/${props.branchId}`) : Promise.resolve(null),
    ])
    services.value = serviceRes.data.data
    branchHours.value = branchRes?.data?.data?.opening_hours_json ?? null
  } catch (err) {
    error.value = parseApiError(err).message
  }
})

const allServiceIds = computed(() => services.value.map((s) => s.id))

// Working days and hours copied off the branch: a salon that opens Mon-Sat
// 9-6 almost always has staff who work those hours, and asking again is a
// question with an obvious answer.
const workingDays = computed(() => {
  if (!branchHours.value) return [1, 2, 3, 4, 5, 6]
  return Object.entries(branchHours.value)
    .filter(([, value]) => Array.isArray(value))
    .map(([key]) => DAY_NUMBERS[key])
    .filter(Boolean)
    .sort((a, b) => a - b)
})

const workingHours = computed(() => {
  const open = Object.values(branchHours.value ?? {}).find((value) => Array.isArray(value))
  return open ? { start: open[0], end: open[1] } : { start: '09:00', end: '18:00' }
})

function chooseSolo() {
  mode.value = 'solo'
  people.value = [
    { name: authStore.user?.name ?? authStore.organization?.name ?? 'Me', phone: '', email: '', serviceIds: [...allServiceIds.value] },
  ]
  save()
}

function chooseTeam() {
  mode.value = 'team'
  people.value = [
    { name: authStore.user?.name ?? '', phone: '', email: '', serviceIds: [...allServiceIds.value] },
  ]
}

const atLimit = computed(() => people.value.length >= FREE_MAX_STAFF)

function addPerson() {
  if (atLimit.value) return
  people.value.push({ name: '', phone: '', email: '', serviceIds: [...allServiceIds.value] })
}

function removePerson(index) {
  people.value.splice(index, 1)
}

function toggleService(person, id) {
  const at = person.serviceIds.indexOf(id)
  if (at === -1) person.serviceIds.push(id)
  else person.serviceIds.splice(at, 1)
}

const canSave = computed(() => people.value.length > 0 && people.value.every((p) => p.name.trim()))

async function save() {
  if (!canSave.value) return
  saving.value = true
  error.value = ''
  try {
    // Sequential, not parallel: the plan limit is counted per request on
    // the server, and ten simultaneous creates could each see a count of
    // nine. One at a time also means the first refusal stops the rest.
    for (const person of people.value) {
      await api.post('/staff', {
        name: person.name.trim(),
        phone: person.phone.trim() || null,
        email: person.email.trim() || null,
        service_ids: person.serviceIds,
        working_days_json: workingDays.value,
        working_hours_json: workingHours.value,
      })
    }
    emit('done')
  } catch (err) {
    error.value = parseApiError(err).message
    // Back to the form so the owner can fix the row rather than lose the
    // list; the ones already created stay created, which the next
    // fetchStatus reflects honestly.
    mode.value = 'team'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <OnboardingLayout
    :step="3"
    title="Who works here?"
    subtitle="Customers pick a person when they book."
    @skip="emit('skip')"
    @back="emit('back')"
  >
    <div v-if="!mode" class="grid gap-3 sm:grid-cols-2">
      <button
        type="button"
        class="rounded-2xl bg-white p-6 text-left shadow-sm ring-1 ring-slate-200 transition hover:ring-indigo-400"
        @click="chooseSolo"
      >
        <span class="block text-lg font-semibold text-slate-900">I work alone</span>
        <span class="mt-1 block text-sm text-slate-500">We'll set you up as the only person customers can book.</span>
      </button>
      <button
        type="button"
        class="rounded-2xl bg-white p-6 text-left shadow-sm ring-1 ring-slate-200 transition hover:ring-indigo-400"
        @click="chooseTeam"
      >
        <span class="block text-lg font-semibold text-slate-900">I have a team</span>
        <span class="mt-1 block text-sm text-slate-500">Add each person and what they do.</span>
      </button>
    </div>

    <div v-else-if="mode === 'team'" class="space-y-4">
      <div v-for="(person, i) in people" :key="i" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <div class="flex items-start justify-between gap-3">
          <div class="min-w-0 flex-1 space-y-3">
            <input
              v-model="person.name"
              type="text"
              placeholder="Name"
              class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
            />
            <div class="grid gap-3 sm:grid-cols-2">
              <input
                v-model="person.phone"
                type="tel"
                placeholder="Phone (optional)"
                class="w-full rounded-lg border border-slate-300 px-3 py-2.5 outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
              />
              <input
                v-model="person.email"
                type="email"
                placeholder="Email — only if they should log in"
                class="w-full rounded-lg border border-slate-300 px-3 py-2.5 outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
              />
            </div>
          </div>
          <button v-if="people.length > 1" type="button" class="text-sm text-slate-400 hover:text-rose-600" @click="removePerson(i)">
            Remove
          </button>
        </div>

        <p class="mt-4 text-sm font-medium text-slate-700">What do they do?</p>
        <div class="mt-2 flex flex-wrap gap-2">
          <button
            v-for="service in services"
            :key="service.id"
            type="button"
            class="rounded-full px-3 py-1.5 text-sm ring-1 transition"
            :class="person.serviceIds.includes(service.id)
              ? 'bg-indigo-600 text-white ring-indigo-600'
              : 'bg-white text-slate-600 ring-slate-300'"
            @click="toggleService(person, service.id)"
          >
            {{ service.name }}
          </button>
        </div>
      </div>

      <button
        type="button"
        :disabled="atLimit"
        class="text-sm font-medium text-indigo-600 disabled:text-slate-400"
        @click="addPerson"
      >
        + Add another person
      </button>
      <p v-if="atLimit" class="text-sm text-slate-500">
        Your free plan covers {{ FREE_MAX_STAFF }} people. Upgrade later to add more.
      </p>
    </div>

    <p v-if="error" class="mt-4 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ error }}</p>

    <template #action>
      <button
        v-if="mode === 'team'"
        type="button"
        :disabled="!canSave || saving"
        class="w-full rounded-xl bg-indigo-600 px-4 py-3 font-semibold text-white shadow-sm transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:bg-slate-300"
        @click="save"
      >
        {{ saving ? 'Saving…' : 'Continue' }}
      </button>
      <p v-else-if="saving" class="text-center text-sm text-slate-500">Setting you up…</p>
    </template>
  </OnboardingLayout>
</template>
```

- [ ] **Step 2: Wire it into the host**

```vue
<StepStaff
  v-else-if="current === 'staff'"
  :branch-id="onboarding.branchId"
  @done="advance('staff')"
  @skip="skip"
  @back="back"
/>
```

- [ ] **Step 3: Check it by hand**

Confirm: "I work alone" creates exactly one staff row named after the owner, carrying every service and the branch's working days; "I have a team" allows a person with a name and phone only, and that person's `users.email` ends in `.invalid`; the eleventh row is refused with the plan message.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/views/onboarding
git commit -m "feat: set up a salon's team, or just its owner, in one tap"
```

---

### Task 11: Screen 4 — make it yours

**Files:**
- Create: `frontend/src/views/onboarding/StepLook.vue`
- Modify: `frontend/src/views/onboarding/OnboardingView.vue`

**Interfaces:**
- Consumes: `GET/PUT /api/settings/organization`, `POST /api/settings/organization/logo`, `POST /api/settings/organization/cover`.
- Produces: component `StepLook` emitting `@done`, `@skip`, `@back`.

- [ ] **Step 1: Read the existing settings form first**

Open `frontend/src/views/SettingsView.vue` and copy its upload call exactly — the field name and the `multipart/form-data` handling must match what `OrganizationSettingController::uploadLogo` expects. Do not invent a shape.

- [ ] **Step 2: Build the screen**

Create `frontend/src/views/onboarding/StepLook.vue`:

```vue
<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '@/lib/api'
import { parseApiError } from '@/lib/errors'
import { useAuthStore } from '@/stores/auth'
import OnboardingLayout from '@/layouts/OnboardingLayout.vue'

const emit = defineEmits(['done', 'skip', 'back'])
const authStore = useAuthStore()

const THEMES = ['#4f46e5', '#0f766e', '#be123c', '#b45309', '#7c3aed', '#0369a1']

const about = ref('')
const themeColor = ref(THEMES[0])
const logoUrl = ref(null)
const saving = ref(false)
const uploading = ref(false)
const error = ref('')

const salonName = computed(() => authStore.organization?.name ?? 'Your salon')

onMounted(async () => {
  try {
    const { data } = await api.get('/settings/organization')
    about.value = data.data.about ?? ''
    themeColor.value = data.data.theme_color || THEMES[0]
    logoUrl.value = data.data.logo_url ?? null
  } catch (err) {
    error.value = parseApiError(err).message
  }
})

async function uploadLogo(event) {
  const file = event.target.files?.[0]
  if (!file) return
  uploading.value = true
  error.value = ''
  try {
    const body = new FormData()
    // Field name must match UploadOrganizationImageRequest — confirm it
    // against SettingsView.vue before changing anything here.
    body.append('image', file)
    const { data } = await api.post('/settings/organization/logo', body)
    logoUrl.value = data.data.logo_url
  } catch (err) {
    error.value = parseApiError(err).message
  } finally {
    uploading.value = false
  }
}

async function save() {
  saving.value = true
  error.value = ''
  try {
    await api.put('/settings/organization', {
      about: about.value.trim() || null,
      theme_color: themeColor.value,
    })
    emit('done')
  } catch (err) {
    error.value = parseApiError(err).message
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <OnboardingLayout
    :step="4"
    title="Make it yours"
    subtitle="Optional — your page already works without this."
    @skip="emit('skip')"
    @back="emit('back')"
  >
    <div class="grid gap-5 sm:grid-cols-2">
      <div class="space-y-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">Your logo</label>
          <input type="file" accept="image/*" class="block w-full text-sm text-slate-600" @change="uploadLogo" />
          <p v-if="uploading" class="mt-1 text-sm text-slate-500">Uploading…</p>
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">A line about your salon</label>
          <textarea
            v-model="about"
            rows="4"
            placeholder="We have cut hair on this street since 1998."
            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
          />
        </div>

        <div>
          <label class="mb-2 block text-sm font-medium text-slate-700">Colour</label>
          <div class="flex flex-wrap gap-2">
            <button
              v-for="colour in THEMES"
              :key="colour"
              type="button"
              class="h-9 w-9 rounded-full ring-2 ring-offset-2 transition"
              :style="{ backgroundColor: colour }"
              :class="themeColor === colour ? 'ring-slate-900' : 'ring-transparent'"
              :aria-label="colour"
              @click="themeColor = colour"
            />
          </div>
        </div>
      </div>

      <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Preview</p>
        <div class="mt-3 overflow-hidden rounded-xl ring-1 ring-slate-200">
          <div class="h-20" :style="{ backgroundColor: themeColor }" />
          <div class="p-4">
            <img v-if="logoUrl" :src="logoUrl" alt="" class="h-12 w-12 rounded-full object-cover ring-2 ring-white" />
            <p class="mt-2 font-semibold text-slate-900">{{ salonName }}</p>
            <p class="mt-1 text-sm text-slate-600">{{ about || 'Your salon story goes here.' }}</p>
          </div>
        </div>
      </div>
    </div>

    <p v-if="error" class="mt-4 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ error }}</p>

    <template #action>
      <button
        type="button"
        :disabled="saving"
        class="w-full rounded-xl bg-indigo-600 px-4 py-3 font-semibold text-white shadow-sm transition hover:bg-indigo-700 disabled:bg-slate-300"
        @click="save"
      >
        {{ saving ? 'Saving…' : 'Continue' }}
      </button>
    </template>
  </OnboardingLayout>
</template>
```

- [ ] **Step 3: Wire it into the host**

```vue
<StepLook v-else-if="current === 'look'" @done="advance('look')" @skip="skip" @back="back" />
```

- [ ] **Step 4: Check it by hand**

Confirm the preview updates live as the colour and text change, an uploaded logo appears in it, and the settings row holds the about text afterwards.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/views/onboarding
git commit -m "feat: let an owner brand their booking page during setup"
```

---

### Task 12: Screen 5 — you're live

**Files:**
- Modify: `frontend/package.json` (add `qrcode`)
- Create: `frontend/src/lib/qrPoster.js`
- Create: `frontend/src/lib/qrPoster.spec.js`
- Create: `frontend/src/views/onboarding/StepDone.vue`
- Modify: `frontend/src/views/onboarding/OnboardingView.vue`

**Interfaces:**
- Consumes: `authStore.organization.slug`, `primary_domain`; `onboarding.complete()` (Task 6).
- Produces: `bookingUrl(organization): string` and `posterCanvas(url, salonName): Promise<HTMLCanvasElement>` from `@/lib/qrPoster`.

- [ ] **Step 1: Install the dependency**

Run: `npm install qrcode`
Expected: `qrcode` appears under `dependencies` in `frontend/package.json`.

- [ ] **Step 2: Write the failing test**

Create `frontend/src/lib/qrPoster.spec.js`:

```js
import { describe, it, expect } from 'vitest'
import { bookingUrl } from './qrPoster'

describe('bookingUrl', () => {
  it('prefers the salon\'s own domain', () => {
    expect(bookingUrl({ slug: 'beautyqueen', primary_domain: 'beautyqueen.salonhub.com' }))
      .toBe('https://beautyqueen.salonhub.com')
  })

  it('falls back to the slug path when no domain has been minted', () => {
    expect(bookingUrl({ slug: 'beautyqueen', primary_domain: null }))
      .toBe(`${window.location.origin}/book/beautyqueen`)
  })

  it('returns an empty string when there is no organization at all', () => {
    expect(bookingUrl(null)).toBe('')
  })
})
```

- [ ] **Step 3: Run it and watch it fail**

Run: `npm run test:unit -- qrPoster`
Expected: FAIL — cannot resolve `./qrPoster`.

- [ ] **Step 4: Write the helper**

Create `frontend/src/lib/qrPoster.js`:

```js
import QRCode from 'qrcode'

/**
 * Where customers book. The salon's own subdomain when one has been
 * minted — that is the address an owner puts on a poster — and the
 * apex-hosted path as a fallback.
 */
export function bookingUrl(organization) {
  if (!organization) return ''
  if (organization.primary_domain) return `https://${organization.primary_domain}`
  return `${window.location.origin}/book/${organization.slug}`
}

const POSTER_WIDTH = 800
const POSTER_HEIGHT = 1000
const QR_SIZE = 520

/**
 * A printable poster: salon name, QR code, and the URL in readable text
 * underneath — a customer whose camera will not scan it can still type it.
 */
export async function posterCanvas(url, salonName) {
  const qr = document.createElement('canvas')
  await QRCode.toCanvas(qr, url, { width: QR_SIZE, margin: 1 })

  const poster = document.createElement('canvas')
  poster.width = POSTER_WIDTH
  poster.height = POSTER_HEIGHT
  const ctx = poster.getContext('2d')

  ctx.fillStyle = '#ffffff'
  ctx.fillRect(0, 0, POSTER_WIDTH, POSTER_HEIGHT)

  ctx.fillStyle = '#0f172a'
  ctx.textAlign = 'center'
  ctx.font = 'bold 48px sans-serif'
  ctx.fillText(salonName, POSTER_WIDTH / 2, 110)

  ctx.font = '28px sans-serif'
  ctx.fillStyle = '#475569'
  ctx.fillText('Book your appointment', POSTER_WIDTH / 2, 160)

  ctx.drawImage(qr, (POSTER_WIDTH - QR_SIZE) / 2, 210)

  ctx.font = '24px sans-serif'
  ctx.fillStyle = '#0f172a'
  ctx.fillText(url.replace(/^https?:\/\//, ''), POSTER_WIDTH / 2, 210 + QR_SIZE + 70)

  return poster
}

export async function downloadPoster(url, salonName) {
  const canvas = await posterCanvas(url, salonName)
  const link = document.createElement('a')
  link.download = `${salonName.replace(/[^a-z0-9]+/gi, '-').toLowerCase()}-booking-qr.png`
  link.href = canvas.toDataURL('image/png')
  link.click()
}
```

- [ ] **Step 5: Run the tests and watch them pass**

Run: `npm run test:unit -- qrPoster`
Expected: PASS, three tests. (`posterCanvas` is not unit-tested — jsdom has no real 2D context — it is checked by hand in Step 8.)

- [ ] **Step 6: Build the screen**

Create `frontend/src/views/onboarding/StepDone.vue`:

> **As shipped.** This snippet is the committed `StepDone.vue` (commits b83ff02,
> 0929fde). It differs from the plan as originally written in three ways, all
> required by review: the screen re-reads `GET /onboarding/status` on mount and
> gates on the server's answer rather than optimistic local state, with a
> distinct third state for a failed read; the clipboard and poster calls both
> degrade instead of failing silently; and it emits `resume`/`leave` rather than
> pushing routes itself.

```vue
<script setup>
import { ref, computed, onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useOnboardingStore } from '@/stores/onboarding'
import { bookingUrl, downloadPoster } from '@/lib/qrPoster'

const emit = defineEmits(['finish', 'resume', 'leave'])

const authStore = useAuthStore()
const onboarding = useOnboardingStore()

const organization = computed(() => authStore.organization)
const salonName = computed(() => organization.value?.name ?? 'Your salon')
const url = computed(() => bookingUrl(organization.value))
const finishing = ref(false)

// markStepDone() only ever flips state locally, optimistically, without
// asking the server. This screen is the one place that tells the owner
// "you're live" — before it makes that claim it has to re-read the real
// status, or it can congratulate someone whose salon cannot take a booking.
const checking = ref(true)

// A failed re-fetch is not the same as the server saying "not ready" — it
// is the server never having answered at all. Falling back to whatever
// `onboarding.steps` already holds (optimistic local flips, or nothing)
// would either congratulate on unverified data or wrongly accuse the
// owner of missing steps that were never actually checked. This is its
// own state so neither the congrats branch nor the missing-steps branch
// can render while the read is unresolved.
const checkFailed = ref(false)

async function checkStatus() {
  checking.value = true
  checkFailed.value = false
  try {
    await onboarding.fetchStatus()
  } catch {
    checkFailed.value = true
  } finally {
    checking.value = false
  }
}

onMounted(checkStatus)

// requiredDone is derived from the server's answer (branch/services/staff),
// never from the optimistic local flips — this is the gate for whether we
// congratulate or confess something is still missing.
const bookable = computed(() => onboarding.requiredDone)

const missing = computed(() =>
  [
    !onboarding.steps.branch && 'your address',
    !onboarding.steps.services && 'your services',
    !onboarding.steps.staff && 'who works there',
  ].filter(Boolean),
)

const shareText = computed(() => `Book an appointment at ${salonName.value}: ${url.value}`)
const whatsapp = computed(() => `https://wa.me/?text=${encodeURIComponent(shareText.value)}`)
const facebook = computed(() => `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(url.value)}`)

// 'idle' | 'copied' | 'unavailable'. `navigator.clipboard` is undefined on
// any non-secure context and on older mobile browsers — this is the
// payoff screen's primary call to action, so a silent throw here would
// leave the button looking like it did nothing on exactly the devices
// most likely to be used to set this up.
const copyState = ref('idle')

async function copy() {
  if (!navigator.clipboard?.writeText) {
    copyState.value = 'unavailable'
    return
  }
  try {
    await navigator.clipboard.writeText(url.value)
    copyState.value = 'copied'
    setTimeout(() => (copyState.value = 'idle'), 2000)
  } catch {
    copyState.value = 'unavailable'
  }
}

// 'idle' | 'downloading' | 'failed'. downloadPoster() was previously fired
// as a floating promise — if canvas rendering or the data-URL conversion
// throws, the owner clicked a button and nothing happened, with no way to
// tell whether it worked.
const posterState = ref('idle')

async function downloadPosterClicked() {
  posterState.value = 'downloading'
  try {
    await downloadPoster(url.value, salonName.value)
    posterState.value = 'idle'
  } catch {
    posterState.value = 'failed'
  }
}

async function finish() {
  finishing.value = true
  try {
    await onboarding.complete()
    emit('finish')
  } finally {
    finishing.value = false
  }
}

// Go fix what's missing. This component already lives inside the
// '/onboarding' route, so pushing back to that same path would be a
// same-route no-op — ask the host to re-run its own resume logic instead,
// which re-fetches status and repositions on the first unsatisfied step.
function resumeSetup() {
  emit('resume')
}

// Leaving is allowed by design everywhere else in the wizard (see
// OnboardingView's leave()) — but unlike finish(), this must not call
// onboarding.complete(). Stamping completion for a salon that is not
// actually bookable would stop the router guard ever sending them back to
// finish setup, and they would have only the dashboard card to notice.
function leaveWithoutCompleting() {
  emit('leave')
}
</script>

<template>
  <div class="min-h-screen bg-slate-50 px-4 py-12">
    <div v-if="checking" class="mx-auto max-w-xl text-center text-slate-500">Checking your setup…</div>

    <div v-else-if="checkFailed" class="mx-auto max-w-xl text-center">
      <div class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-slate-200 text-2xl">?</div>
      <h1 class="mt-4 font-[Fraunces_Variable,serif] text-3xl font-semibold text-slate-900">Couldn't check your setup</h1>
      <p class="mt-2 text-slate-600">
        We weren't able to reach the server to confirm {{ salonName }} is ready to take bookings.
      </p>
      <button
        type="button"
        class="mt-6 w-full rounded-xl bg-indigo-600 px-4 py-3 font-semibold text-white transition hover:bg-indigo-700"
        @click="checkStatus"
      >
        Try again
      </button>
    </div>

    <div v-else-if="!bookable" class="mx-auto max-w-xl text-center">
      <div class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-amber-100 text-2xl">!</div>
      <h1 class="mt-4 font-[Fraunces_Variable,serif] text-3xl font-semibold text-slate-900">Almost there</h1>
      <p class="mt-2 text-slate-600">
        {{ salonName }} isn't ready to take bookings yet.
      </p>

      <div class="mt-4 rounded-xl bg-amber-50 px-4 py-3 text-left text-sm text-amber-800">
        You still need to add {{ missing.join(' and ') }} before customers can book you.
      </div>

      <button
        type="button"
        class="mt-6 w-full rounded-xl bg-indigo-600 px-4 py-3 font-semibold text-white transition hover:bg-indigo-700"
        @click="resumeSetup"
      >
        Finish setup
      </button>

      <button
        type="button"
        class="mt-3 text-sm font-medium text-slate-500 transition hover:text-slate-900"
        @click="leaveWithoutCompleting"
      >
        I'll do this later
      </button>
    </div>

    <div v-else class="mx-auto max-w-xl text-center">
      <div class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-emerald-100 text-2xl">✓</div>
      <h1 class="mt-4 font-[Fraunces_Variable,serif] text-3xl font-semibold text-slate-900">
        {{ salonName }} is live
      </h1>
      <p class="mt-2 text-slate-600">Share this link and customers can book you right now.</p>

      <div class="mt-6 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <p class="select-all break-all text-lg font-medium text-indigo-700">{{ url }}</p>
        <button
          type="button"
          class="mt-3 w-full rounded-xl bg-indigo-600 px-4 py-3 font-semibold text-white transition hover:bg-indigo-700"
          @click="copy"
        >
          {{
            copyState === 'copied'
              ? 'Copied'
              : copyState === 'unavailable'
                ? "Can't copy automatically — select the link above"
                : 'Copy link'
          }}
        </button>

        <div class="mt-3 grid grid-cols-2 gap-3">
          <a :href="whatsapp" target="_blank" rel="noopener" class="rounded-xl bg-emerald-600 px-4 py-2.5 font-medium text-white transition hover:bg-emerald-700">
            WhatsApp
          </a>
          <a :href="facebook" target="_blank" rel="noopener" class="rounded-xl bg-blue-600 px-4 py-2.5 font-medium text-white transition hover:bg-blue-700">
            Facebook
          </a>
        </div>

        <button
          type="button"
          :disabled="posterState === 'downloading'"
          class="mt-3 w-full rounded-xl px-4 py-2.5 font-medium text-slate-700 ring-1 ring-slate-300 transition hover:bg-slate-50"
          @click="downloadPosterClicked"
        >
          {{
            posterState === 'downloading'
              ? 'Preparing your poster…'
              : posterState === 'failed'
                ? "Couldn't create the poster — try again"
                : 'Download QR poster for your shop'
          }}
        </button>

        <a :href="url" target="_blank" rel="noopener" class="mt-3 block text-sm font-medium text-indigo-600">
          Try booking yourself &rarr;
        </a>
      </div>

      <button
        type="button"
        :disabled="finishing"
        class="mt-6 text-sm font-medium text-slate-500 transition hover:text-slate-900"
        @click="finish"
      >
        {{ finishing ? 'One moment…' : 'Go to dashboard' }}
      </button>
    </div>
  </div>
</template>
```

- [ ] **Step 7: Wire it into the host**

```vue
<StepDone v-else @finish="leave" @leave="leave" @resume="resume" />
```

`finish()` in `OnboardingView.vue` is now unused — `StepDone` calls `onboarding.complete()` itself and emits. Delete `finish()` from the host and keep `leave()`.

`resume` exists because the plan originally had `StepDone` call `router.push('/onboarding')` to send an owner back to an unfinished step — a no-op, since the wizard *is* `/onboarding`. Only the host knows the step index, so the screen asks and the host moves.

- [ ] **Step 8: Test that the wizard resumes where the owner left off**

All five screens now exist, so the host can be mounted with them stubbed. Create `frontend/src/views/onboarding/OnboardingView.spec.js`:

```js
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal()
  return { ...actual, default: { get: vi.fn(), post: vi.fn(), put: vi.fn() } }
})
vi.mock('vue-router', () => ({ useRouter: () => ({ push: vi.fn() }) }))

import api from '@/lib/api'
import OnboardingView from './OnboardingView.vue'

const statusWith = (steps, nextStep) => ({
  data: { data: { completed_at: null, branch_id: 7, steps, next_step: nextStep } },
})

const mountView = () =>
  mount(OnboardingView, {
    global: {
      stubs: {
        StepBranch: { template: '<div data-test="branch" />' },
        StepServices: { template: '<div data-test="services" />' },
        StepStaff: { template: '<div data-test="staff" />' },
        StepLook: { template: '<div data-test="look" />' },
        StepDone: { template: '<div data-test="done" />' },
      },
    },
  })

describe('OnboardingView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(api.get).mockReset()
  })

  it('opens on the first step a fresh salon has not done', async () => {
    vi.mocked(api.get).mockResolvedValue(
      statusWith({ branch: false, services: false, staff: false, look: false }, 'branch'),
    )

    const wrapper = mountView()
    await flushPromises()

    expect(wrapper.find('[data-test="branch"]').exists()).toBe(true)
  })

  it('skips past the steps already finished', async () => {
    vi.mocked(api.get).mockResolvedValue(
      statusWith({ branch: true, services: true, staff: false, look: false }, 'staff'),
    )

    const wrapper = mountView()
    await flushPromises()

    expect(wrapper.find('[data-test="branch"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="staff"]').exists()).toBe(true)
  })

  it('goes straight to the payoff screen when everything is already set up', async () => {
    vi.mocked(api.get).mockResolvedValue(
      statusWith({ branch: true, services: true, staff: true, look: true }, 'done'),
    )

    const wrapper = mountView()
    await flushPromises()

    expect(wrapper.find('[data-test="done"]').exists()).toBe(true)
  })
})
```

Run: `npm run test:unit -- OnboardingView`
Expected: PASS, three tests.

- [ ] **Step 9: Check it by hand**

Confirm the poster downloads as a PNG with the salon name above a scannable QR code and the URL printed below it, scanning it on a phone opens the booking page, and "Go to dashboard" stamps `onboarding_completed_at` so a reload does not reopen the wizard.

- [ ] **Step 10: Commit**

```bash
git add frontend/package.json frontend/package-lock.json frontend/src/lib/qrPoster.js frontend/src/lib/qrPoster.spec.js frontend/src/views/onboarding
git commit -m "feat: end setup with a shareable booking link and a QR poster"
```

---

### Task 13: Keep reminding an owner who skipped

**Files:**
- Create: `frontend/src/components/SetupChecklistCard.vue`
- Modify: `frontend/src/views/DashboardView.vue`

**Interfaces:**
- Consumes: `useOnboardingStore` (Task 6).
- Produces: `SetupChecklistCard`, self-contained — it fetches its own status and renders nothing when setup is complete.

- [ ] **Step 1: Build the card**

Create `frontend/src/components/SetupChecklistCard.vue`:

```vue
<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useOnboardingStore } from '@/stores/onboarding'

const router = useRouter()
const authStore = useAuthStore()
const onboarding = useOnboardingStore()

const LABELS = {
  branch: 'Add your address and opening hours',
  services: 'Add your services and prices',
  staff: 'Add who works there',
  look: 'Add your logo and salon story',
}

const dismissing = ref(false)

onMounted(() => {
  if (authStore.isOwner) onboarding.fetchStatus().catch(() => {})
})

const items = computed(() =>
  Object.entries(LABELS).map(([key, label]) => ({ key, label, done: onboarding.steps[key] })),
)
const doneCount = computed(() => items.value.filter((item) => item.done).length)

// Owners only, and only while something is genuinely unfinished. A salon
// that has completed the wizard, or that has every step satisfied, is not
// nagged.
const show = computed(() => authStore.isOwner && !onboarding.isComplete && doneCount.value < items.value.length)

async function dismiss() {
  dismissing.value = true
  try {
    await onboarding.complete()
  } finally {
    dismissing.value = false
  }
}
</script>

<template>
  <section v-if="show" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-indigo-200">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <div>
        <h2 class="font-semibold text-slate-900">Finish setting up your salon</h2>
        <p class="text-sm text-slate-500">{{ doneCount }} of {{ items.length }} done</p>
      </div>
      <button
        type="button"
        class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-indigo-700"
        @click="router.push('/onboarding')"
      >
        Continue setup
      </button>
    </div>

    <ul class="mt-4 space-y-2">
      <li v-for="item in items" :key="item.key" class="flex items-center gap-2 text-sm">
        <span
          class="grid h-5 w-5 shrink-0 place-items-center rounded-full text-xs"
          :class="item.done ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-400'"
        >
          {{ item.done ? '✓' : '' }}
        </span>
        <span :class="item.done ? 'text-slate-400 line-through' : 'text-slate-700'">{{ item.label }}</span>
      </li>
    </ul>

    <button
      type="button"
      :disabled="dismissing"
      class="mt-4 text-sm text-slate-400 transition hover:text-slate-700"
      @click="dismiss"
    >
      Don't show this again
    </button>
  </section>
</template>
```

- [ ] **Step 2: Mount it on the dashboard**

In `frontend/src/views/DashboardView.vue`, add the import beside `SubdomainBanner`:

```js
import SetupChecklistCard from '@/components/SetupChecklistCard.vue'
```

and render it directly above the tiles row in the template, below `<SubdomainBanner />`:

```vue
<SetupChecklistCard class="mb-6" />
```

- [ ] **Step 3: Check it by hand**

Confirm: an owner who skips the wizard lands on the dashboard with the card showing the right count; finishing a step from the card and returning updates it; "Don't show this again" removes it permanently across reloads; a manager or staff member never sees it.

- [ ] **Step 4: Run everything**

Run: `cd backend && php artisan test` then `cd ../frontend && npm run test:unit`
Expected: both suites PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/components/SetupChecklistCard.vue frontend/src/views/DashboardView.vue
git commit -m "feat: remind an owner of the setup they have not finished"
```

---

## Notes for the implementer

**`parseApiError`** lives at `frontend/src/lib/errors.js` and is used by every existing view. Read it before Task 8 — the plan assumes it returns `{ message, errors }` where `errors` is Laravel's validation bag. If its actual shape differs, adapt the screens rather than the library.

**Branch `show` endpoint.** Tasks 8 and 10 call `GET /branches/{id}`. It exists (`apiResource('branches')`) and returns `{"data": {...}}` through `BranchResource`. Confirm the `opening_hours_json` key name on that resource before wiring the hours grid — the plan assumes it is passed through unchanged.

**Order matters.** Tasks 1–5 are backend and independent of each other after Task 1. Tasks 6–7 must land before 8–13, and Task 12 depends on Task 6's store. Task 13 can be built any time after Task 6 but is best last, since it is the safety net for everything before it.
