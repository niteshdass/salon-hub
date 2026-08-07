# Salon Discovery Search Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A customer arriving at SalonHub can find a salon by typing part of its name, city, or a service, and reach that salon's shopfront in one click.

**Architecture:** One new cross-tenant, unauthenticated endpoint (`GET /api/discover/salons`) backed by a single eligibility scope on `Organization`, plus a `/salons` search page in the SPA wearing the marketing (light) theme. The endpoint is deliberately outside both tenant route groups; with no tenant bound the `BelongsToOrganization` global scope is inert, so the controller is the only thing preventing a cross-tenant leak and therefore whitelists every field it emits.

**Tech Stack:** Laravel 12 / PHP 8.4, PHPUnit + `RefreshDatabase` (sqlite `:memory:` in tests, MySQL in production), Vue 3 `<script setup>`, Vue Router, Tailwind v4, Vitest + `@vue/test-utils`.

**Spec:** `docs/superpowers/specs/2026-08-08-salon-discovery-search-design.md`

## Global Constraints

- Endpoint path is exactly `/api/discover/salons`. It must NOT be added to the `public/{org}` or `public-site` route groups — both apply `public.tenant`, which binds a tenant and would filter results to one salon.
- The controller never serialises a model. Every emitted field is whitelisted by hand.
- SQL must run on both sqlite (tests) and MySQL (production): use `LOWER(col) LIKE ?` for case-insensitive matching, and no MySQL-only functions.
- Rating is `null` unless the salon has **3 or more** published reviews.
- Only `status = active` services are matched, priced, or previewed.
- Page size is 12.
- Rate limit: `throttle:60,1`.
- The `/salons` page uses the marketing light theme (`bg-paper`, `text-ink`, `brand-*`), NOT the dark brass shopfront skin.
- No change to `/` in this plan.
- Run backend tests from `backend/`, frontend tests from `frontend/`.

---

### Task 1: Eligibility scope and the endpoint skeleton

Salons that cannot take a booking must never appear. This task delivers the route, the controller, and the eligibility rule, returning only identity fields — pricing, ratings, search and ordering arrive in later tasks.

**Files:**
- Modify: `backend/app/Models/Organization.php` (add `scopeListable`)
- Create: `backend/app/Http/Controllers/Public/DiscoveryController.php`
- Modify: `backend/routes/api.php` (new route group at the end, beside the `contact` route)
- Test: `backend/tests/Feature/Public/DiscoveryTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `Organization::query()->listable()` — Eloquent scope, returns `Builder`.
  - `GET /api/discover/salons` → `{ data: [{slug, name, city, cover_image_url, logo_url, currency}], meta: {total, page, per_page} }`.
  - `DiscoveryController::url(?string $path): ?string`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Public/DiscoveryTest.php`:

```php
<?php

namespace Tests\Feature\Public;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Cross-tenant salon discovery: who is listed, what a card shows, what the
 * search box matches, and in what order results arrive.
 */
class DiscoveryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A salon that can take a booking: active, onboarded, one branch, one
     * active service. Every eligibility test starts from this and breaks
     * exactly one condition.
     */
    private function salon(string $slug, array $overrides = []): Organization
    {
        $org = Organization::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'email' => "hello@{$slug}.test",
            'phone' => '+880 1700 000000',
            'currency' => 'BDT',
            'subscription_plan' => 'free',
            'status' => 'active',
            'onboarding_completed_at' => now(),
        ], $overrides));

        Branch::create([
            'organization_id' => $org->id,
            'name' => 'Main',
            'city' => 'Sylhet',
            'address' => '1 Zindabazar',
        ]);

        Service::create([
            'organization_id' => $org->id,
            'name' => 'Hair cut',
            'duration' => 30,
            'price' => 500,
            'status' => 'active',
        ]);

        return $org;
    }

    public function test_it_lists_a_salon_that_can_take_a_booking(): void
    {
        $this->salon('chastity-hyde');

        $response = $this->getJson('/api/discover/salons');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.slug', 'chastity-hyde');
        $response->assertJsonPath('data.0.name', 'Chastity hyde');
        $response->assertJsonPath('data.0.city', 'Sylhet');
        $response->assertJsonPath('data.0.currency', 'BDT');
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('meta.page', 1);
        $response->assertJsonPath('meta.per_page', 12);
    }

    public function test_it_hides_a_suspended_salon(): void
    {
        $this->salon('open-one');
        $this->salon('closed-one', ['status' => 'suspended']);

        $response = $this->getJson('/api/discover/salons');

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.slug', 'open-one');
    }

    public function test_it_hides_a_salon_that_never_finished_onboarding(): void
    {
        $this->salon('open-one');
        $this->salon('half-done', ['onboarding_completed_at' => null]);

        $response = $this->getJson('/api/discover/salons');

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.slug', 'open-one');
    }

    public function test_it_hides_a_salon_with_no_branch(): void
    {
        $this->salon('open-one');
        $nowhere = $this->salon('nowhere');
        Branch::withoutGlobalScopes()->where('organization_id', $nowhere->id)->delete();

        $response = $this->getJson('/api/discover/salons');

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.slug', 'open-one');
    }

    public function test_it_hides_a_salon_whose_only_service_is_inactive(): void
    {
        $this->salon('open-one');
        $quiet = $this->salon('nothing-to-book');
        Service::withoutGlobalScopes()
            ->where('organization_id', $quiet->id)
            ->update(['status' => 'inactive']);

        $response = $this->getJson('/api/discover/salons');

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.slug', 'open-one');
    }

    public function test_it_pages_results_without_repeating_a_salon(): void
    {
        foreach (range(1, 14) as $n) {
            $this->salon('salon-'.str_pad((string) $n, 2, '0', STR_PAD_LEFT));
        }

        $first = $this->getJson('/api/discover/salons')->assertOk();
        $second = $this->getJson('/api/discover/salons?page=2')->assertOk();

        $this->assertCount(12, $first->json('data'));
        $this->assertCount(2, $second->json('data'));
        $this->assertSame(14, $first->json('meta.total'));
        $this->assertSame(2, $second->json('meta.page'));

        $slugs = array_merge(
            array_column($first->json('data'), 'slug'),
            array_column($second->json('data'), 'slug'),
        );
        $this->assertSame($slugs, array_unique($slugs));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd backend && php artisan test --filter=DiscoveryTest`
Expected: FAIL — every test 404s, the route does not exist yet.

- [ ] **Step 3: Add the eligibility scope**

In `backend/app/Models/Organization.php`, add the imports it needs (`use App\Enums\ServiceStatus;`, `use Illuminate\Database\Eloquent\Builder;` — `OrganizationStatus` is already imported) and add this method after the relation methods:

```php
    /**
     * Salons that may appear in public discovery: open for business, finished
     * setting up, somewhere to go, and something to book.
     *
     * One scope so the listing and anything built on it later cannot drift
     * into disagreeing about who is listed.
     *
     * @param  Builder<Organization>  $query
     * @return Builder<Organization>
     */
    public function scopeListable(Builder $query): Builder
    {
        return $query
            ->where('status', OrganizationStatus::ACTIVE)
            ->whereNotNull('onboarding_completed_at')
            ->whereHas('branches')
            ->whereHas('services', fn (Builder $services) => $services
                ->where('status', ServiceStatus::ACTIVE));
    }
```

- [ ] **Step 4: Write the controller**

Create `backend/app/Http/Controllers/Public/DiscoveryController.php`:

```php
<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Cross-tenant salon discovery for a customer who has arrived at the platform
 * without a salon in mind.
 *
 * Unlike every other public endpoint this one runs with NO tenant bound, which
 * leaves the BelongsToOrganization global scope inert by design. Nothing but
 * this class stands between a cross-tenant query and a leak, so every field
 * below is whitelisted by hand and no model is ever serialised.
 */
class DiscoveryController extends Controller
{
    /** Salons per page. */
    protected const PER_PAGE = 12;

    public function __invoke(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));

        $query = Organization::query()->listable()->orderBy('name');

        $total = (clone $query)->count();

        /** @var Collection<int, Organization> $salons */
        $salons = $query
            ->forPage($page, self::PER_PAGE)
            ->get(['id', 'name', 'slug', 'currency', 'logo', 'cover_image']);

        $cities = $this->citiesFor($salons->pluck('id')->all());

        return response()->json([
            'data' => $salons->map(fn (Organization $salon) => [
                'slug' => $salon->slug,
                'name' => $salon->name,
                'city' => $cities[$salon->id] ?? null,
                'cover_image_url' => $this->url($salon->cover_image),
                'logo_url' => $this->url($salon->logo),
                'currency' => $salon->currency,
            ])->values()->all(),
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => self::PER_PAGE,
            ],
        ]);
    }

    /**
     * The city of each salon's oldest branch, keyed by organization id. A
     * salon may have several branches; the first one it created is the one it
     * is known by.
     *
     * @param  array<int, int>  $organizationIds
     * @return array<int, string|null>
     */
    protected function citiesFor(array $organizationIds): array
    {
        if (! $organizationIds) {
            return [];
        }

        return Branch::query()
            ->whereIn('organization_id', $organizationIds)
            ->orderByDesc('id')
            ->pluck('city', 'organization_id')
            ->all();
    }

    protected function url(?string $path): ?string
    {
        return $path ? Storage::disk('public')->url($path) : null;
    }
}
```

Note on `citiesFor`: `pluck` keeps the LAST row per key, so ordering by `id` **descending** leaves the oldest branch's city in the map.

- [ ] **Step 5: Register the route**

In `backend/routes/api.php`, add the import `use App\Http\Controllers\Public\DiscoveryController;` alongside the other `Public\` imports, and append this at the end of the file, after the `contact` route:

```php
// Cross-tenant salon discovery for the platform's own search page. Public and
// deliberately NOT tenant-scoped: the point is to look across organizations,
// which is why it lives outside both the `public/{org}` and `public-site`
// groups — `public.tenant` would bind one salon and filter the rest away.
// Throttled because a debounced search box is chatty and an unauthenticated
// cross-tenant endpoint should not be free to scrape.
Route::get('discover/salons', DiscoveryController::class)->middleware('throttle:60,1');
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `cd backend && php artisan test --filter=DiscoveryTest`
Expected: PASS (6 tests).

- [ ] **Step 7: Commit**

```bash
git add backend/app/Models/Organization.php backend/app/Http/Controllers/Public/DiscoveryController.php backend/routes/api.php backend/tests/Feature/Public/DiscoveryTest.php
git commit -m "feat(discovery): list salons that can take a booking"
```

---

### Task 2: Card details — price, services, rating

A name alone is not enough to choose. This adds what the card shows, including the rule that a rating stays hidden until three published reviews exist.

**Files:**
- Modify: `backend/app/Http/Controllers/Public/DiscoveryController.php`
- Test: `backend/tests/Feature/Public/DiscoveryTest.php`

**Interfaces:**
- Consumes: `Organization::query()->listable()`, `DiscoveryController::url()` from Task 1.
- Produces: each `data[]` entry gains `price_from` (string decimal or null), `services` (array of up to 3 strings), `rating` (`{average: float, count: int}` or null).

- [ ] **Step 1: Write the failing tests**

Append to `backend/tests/Feature/Public/DiscoveryTest.php` (add `use App\Models\Appointment;`, `use App\Models\Customer;`, `use App\Models\Review;` and `use App\Models\User;` to the imports — a review cannot exist without an appointment, which cannot exist without a customer and a staff member):

```php
    public function test_a_card_shows_the_cheapest_active_service_and_a_service_preview(): void
    {
        $org = $this->salon('chastity-hyde');
        Service::create([
            'organization_id' => $org->id,
            'name' => 'Hair spa',
            'duration' => 60,
            'price' => 1200,
            'status' => 'active',
        ]);
        Service::create([
            'organization_id' => $org->id,
            'name' => 'Bridal package',
            'duration' => 180,
            'price' => 100,
            'status' => 'inactive',
        ]);
        Service::create([
            'organization_id' => $org->id,
            'name' => 'Facial',
            'duration' => 45,
            'price' => 900,
            'status' => 'active',
        ]);
        Service::create([
            'organization_id' => $org->id,
            'name' => 'Manicure',
            'duration' => 30,
            'price' => 700,
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/discover/salons');

        // 100 belongs to the inactive service and must not be advertised.
        $this->assertSame('500.00', $response->json('data.0.price_from'));
        $this->assertSame(
            ['Hair cut', 'Hair spa', 'Facial'],
            $response->json('data.0.services'),
        );
    }

    public function test_a_salon_with_two_published_reviews_shows_no_rating(): void
    {
        $org = $this->salon('chastity-hyde');
        $this->review($org, 5);
        $this->review($org, 5);

        $response = $this->getJson('/api/discover/salons');

        $this->assertNull($response->json('data.0.rating'));
    }

    public function test_a_salon_with_three_published_reviews_shows_its_rating(): void
    {
        $org = $this->salon('chastity-hyde');
        $this->review($org, 5);
        $this->review($org, 4);
        $this->review($org, 4);
        $this->review($org, 1, 'hidden');

        $response = $this->getJson('/api/discover/salons');

        // The hidden review counts for neither the average nor the count.
        $response->assertJsonPath('data.0.rating.average', 4.3);
        $response->assertJsonPath('data.0.rating.count', 3);
    }

    /**
     * A review attached to a salon. `reviews.appointment_id` is NOT NULL and
     * unique — a review only exists because someone was served — so each one
     * needs its own booking.
     */
    private function review(Organization $org, int $rating, string $status = 'published'): Review
    {
        return Review::create([
            'organization_id' => $org->id,
            'appointment_id' => $this->booking($org)->id,
            'rating' => $rating,
            'comment' => 'Lovely.',
            'reviewer_name' => 'Sadia Rahman',
            'status' => $status,
        ]);
    }

    /** One booking taken by a salon, which is what "still running" means here. */
    private function booking(Organization $org): Appointment
    {
        $branch = Branch::withoutGlobalScopes()->where('organization_id', $org->id)->firstOrFail();
        $service = Service::withoutGlobalScopes()->where('organization_id', $org->id)->firstOrFail();

        $staff = User::create([
            'organization_id' => $org->id,
            'branch_id' => $branch->id,
            'name' => 'Rifat',
            'email' => 'rifat-'.fake()->unique()->numerify('####').'@'.$org->slug.'.test',
            'password' => bcrypt('secret1234'),
            'role' => 'staff',
            'status' => 'active',
        ]);

        $customer = Customer::create([
            'organization_id' => $org->id,
            'name' => 'Nabila',
            'phone' => '555-'.fake()->unique()->numerify('####'),
        ]);

        return Appointment::create([
            'organization_id' => $org->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'staff_id' => $staff->id,
            'service_id' => $service->id,
            'booking_date' => now()->addDay()->toDateString(),
            'start_time' => '10:00',
            'end_time' => '10:30',
            'status' => 'confirmed',
        ]);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd backend && php artisan test --filter=DiscoveryTest`
Expected: FAIL — `price_from`, `services` and `rating` are missing from the payload (null returned where values are expected).

- [ ] **Step 3: Add the aggregates to the controller**

In `DiscoveryController`, add `use App\Enums\ServiceStatus;`, `use App\Models\Review;` and `use App\Models\Service;` to the imports. Add the minimum-price aggregate to the query in `__invoke`, replacing the `$salons = …` assignment:

```php
        /** @var Collection<int, Organization> $salons */
        $salons = $query
            ->withMin(
                ['services as price_from' => fn ($services) => $services
                    ->where('status', ServiceStatus::ACTIVE)],
                'price',
            )
            ->forPage($page, self::PER_PAGE)
            ->get(['id', 'name', 'slug', 'currency', 'logo', 'cover_image']);

        $ids = $salons->pluck('id')->all();
        $cities = $this->citiesFor($ids);
        $services = $this->servicesFor($ids);
        $ratings = $this->ratingsFor($ids);
```

(delete the old standalone `$cities = …` line), and extend the mapped card:

```php
            'data' => $salons->map(fn (Organization $salon) => [
                'slug' => $salon->slug,
                'name' => $salon->name,
                'city' => $cities[$salon->id] ?? null,
                'cover_image_url' => $this->url($salon->cover_image),
                'logo_url' => $this->url($salon->logo),
                'currency' => $salon->currency,
                'price_from' => $salon->price_from !== null
                    ? number_format((float) $salon->price_from, 2, '.', '')
                    : null,
                'rating' => $ratings[$salon->id] ?? null,
                'services' => $services[$salon->id] ?? [],
            ])->values()->all(),
```

Then add the two helpers below `citiesFor`:

```php
    /**
     * Up to three active service names per salon, oldest first — enough for a
     * customer to see what kind of salon this is without opening it.
     *
     * @param  array<int, int>  $organizationIds
     * @return array<int, array<int, string>>
     */
    protected function servicesFor(array $organizationIds): array
    {
        if (! $organizationIds) {
            return [];
        }

        return Service::query()
            ->whereIn('organization_id', $organizationIds)
            ->where('status', ServiceStatus::ACTIVE)
            ->orderBy('id')
            ->get(['organization_id', 'name'])
            ->groupBy('organization_id')
            ->map(fn (Collection $rows) => $rows->take(3)->pluck('name')->all())
            ->all();
    }

    /**
     * Published-review average and count per salon, but only once a salon has
     * three of them.
     *
     * A single review is noise, and a blank rating beside an established
     * salon's reads as "bad" when it only means "new" — so a salon below the
     * threshold shows no rating at all rather than a thin one.
     *
     * @param  array<int, int>  $organizationIds
     * @return array<int, array{average: float, count: int}>
     */
    protected function ratingsFor(array $organizationIds): array
    {
        if (! $organizationIds) {
            return [];
        }

        return Review::query()
            ->whereIn('organization_id', $organizationIds)
            ->where('status', 'published')
            ->selectRaw('organization_id, AVG(rating) as avg_rating, COUNT(*) as cnt')
            ->groupBy('organization_id')
            ->get()
            ->filter(fn ($row) => (int) $row->cnt >= 3)
            ->mapWithKeys(fn ($row) => [
                (int) $row->organization_id => [
                    'average' => round((float) $row->avg_rating, 1),
                    'count' => (int) $row->cnt,
                ],
            ])
            ->all();
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd backend && php artisan test --filter=DiscoveryTest`
Expected: PASS (9 tests).

- [ ] **Step 5: Commit**

```bash
git add backend/app/Http/Controllers/Public/DiscoveryController.php backend/tests/Feature/Public/DiscoveryTest.php
git commit -m "feat(discovery): show price, services and a rating worth trusting"
```

---

### Task 3: The search box

`q` is the whole point of the page. It matches what a customer would actually type: a salon name, a city, or the thing they want done.

**Files:**
- Modify: `backend/app/Http/Controllers/Public/DiscoveryController.php`
- Test: `backend/tests/Feature/Public/DiscoveryTest.php`

**Interfaces:**
- Consumes: everything from Tasks 1–2.
- Produces: `GET /api/discover/salons?q=<text>` — optional, trimmed, truncated to 80 characters; empty `q` browses everything eligible.

- [ ] **Step 1: Write the failing tests**

Append to `backend/tests/Feature/Public/DiscoveryTest.php`:

```php
    public function test_it_matches_a_salon_by_name_case_insensitively(): void
    {
        $this->salon('chastity-hyde');
        $this->salon('heaven-touch');

        $response = $this->getJson('/api/discover/salons?q=CHASTITY');

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.slug', 'chastity-hyde');
    }

    public function test_it_matches_a_salon_by_slug(): void
    {
        $this->salon('chastity-hyde');
        $this->salon('heaven-touch');

        $response = $this->getJson('/api/discover/salons?q=heaven-touch');

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.slug', 'heaven-touch');
    }

    public function test_it_matches_a_salon_by_city(): void
    {
        $this->salon('chastity-hyde');
        $dhaka = $this->salon('heaven-touch');
        Branch::withoutGlobalScopes()
            ->where('organization_id', $dhaka->id)
            ->update(['city' => 'Dhaka']);

        $response = $this->getJson('/api/discover/salons?q=dhaka');

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.slug', 'heaven-touch');
    }

    public function test_it_matches_a_salon_by_service_name(): void
    {
        $this->salon('chastity-hyde');
        $spa = $this->salon('heaven-touch');
        Service::create([
            'organization_id' => $spa->id,
            'name' => 'Hot stone massage',
            'duration' => 60,
            'price' => 2000,
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/discover/salons?q=massage');

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.slug', 'heaven-touch');
    }

    public function test_it_never_matches_an_inactive_service(): void
    {
        $org = $this->salon('chastity-hyde');
        Service::create([
            'organization_id' => $org->id,
            'name' => 'Hot stone massage',
            'duration' => 60,
            'price' => 2000,
            'status' => 'inactive',
        ]);

        $response = $this->getJson('/api/discover/salons?q=massage');

        $response->assertJsonCount(0, 'data');
        $response->assertJsonPath('meta.total', 0);
    }

    public function test_an_empty_query_browses_every_listed_salon(): void
    {
        $this->salon('chastity-hyde');
        $this->salon('heaven-touch');

        $response = $this->getJson('/api/discover/salons?q=   ');

        $response->assertJsonCount(2, 'data');
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd backend && php artisan test --filter=DiscoveryTest`
Expected: FAIL — `q` is ignored, so the filtered tests return every salon (2 instead of 1).

- [ ] **Step 3: Implement matching**

In `DiscoveryController::__invoke`, replace the `$query = …` line with:

```php
        $term = $this->term($request);

        $query = Organization::query()->listable()->orderBy('name');

        if ($term !== null) {
            $like = '%'.$term.'%';

            $query->where(function ($match) use ($like) {
                $match
                    ->whereRaw('LOWER(organizations.name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(organizations.slug) LIKE ?', [$like])
                    ->orWhereHas('branches', fn ($branches) => $branches
                        ->whereRaw('LOWER(branches.city) LIKE ?', [$like]))
                    ->orWhereHas('services', fn ($services) => $services
                        ->where('status', ServiceStatus::ACTIVE)
                        ->whereRaw('LOWER(services.name) LIKE ?', [$like]));
            });
        }
```

and add the helper below `__invoke`:

```php
    /**
     * The search term, lowercased for matching, or null when the box is empty.
     *
     * Lowercasing both sides rather than trusting collation keeps sqlite (in
     * tests) and MySQL (in production) agreeing. The length cap bounds the
     * LIKE pattern; nobody types a salon name longer than this.
     */
    protected function term(Request $request): ?string
    {
        $raw = $request->query('q');
        $term = is_string($raw) ? trim($raw) : '';

        return $term === '' ? null : mb_strtolower(mb_substr($term, 0, 80));
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd backend && php artisan test --filter=DiscoveryTest`
Expected: PASS (15 tests).

- [ ] **Step 5: Commit**

```bash
git add backend/app/Http/Controllers/Public/DiscoveryController.php backend/tests/Feature/Public/DiscoveryTest.php
git commit -m "feat(discovery): match salons by name, city and service"
```

---

### Task 4: Ordering

Someone typing "chastity" wants that salon first, not every salon offering a similarly-named service. With no query, the salons that are actually running come first.

**Files:**
- Modify: `backend/app/Http/Controllers/Public/DiscoveryController.php`
- Test: `backend/tests/Feature/Public/DiscoveryTest.php`

**Interfaces:**
- Consumes: everything from Tasks 1–3.
- Produces: deterministic result order — name match, then most recent booking, then name A–Z. No payload change.

- [ ] **Step 1: Write the failing tests**

Append to `backend/tests/Feature/Public/DiscoveryTest.php`:

```php
    public function test_a_name_match_outranks_a_service_only_match(): void
    {
        // "Zenith" is named for what the other salon merely sells, and sorts
        // last alphabetically — so only ranking can put it first.
        $this->salon('zenith-massage');
        $other = $this->salon('aabode-spa');
        Service::create([
            'organization_id' => $other->id,
            'name' => 'Hot stone massage',
            'duration' => 60,
            'price' => 2000,
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/discover/salons?q=massage');

        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.slug', 'zenith-massage');
        $response->assertJsonPath('data.1.slug', 'aabode-spa');
    }

    public function test_a_salon_taking_bookings_outranks_a_quiet_one(): void
    {
        // Alphabetical order would put "aabode" first; recent activity must not.
        $this->salon('aabode-spa');
        $busy = $this->salon('zenith-massage');
        $this->booking($busy);

        $response = $this->getJson('/api/discover/salons');

        $response->assertJsonPath('data.0.slug', 'zenith-massage');
        $response->assertJsonPath('data.1.slug', 'aabode-spa');
    }

    public function test_equally_quiet_salons_are_ordered_by_name(): void
    {
        $this->salon('zenith-massage');
        $this->salon('aabode-spa');

        $response = $this->getJson('/api/discover/salons');

        $response->assertJsonPath('data.0.slug', 'aabode-spa');
        $response->assertJsonPath('data.1.slug', 'zenith-massage');
    }
```

The `booking()` helper these use already exists in this file — Task 2 added it so each
review could hang off a real appointment.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd backend && php artisan test --filter=DiscoveryTest`
Expected: FAIL on the first two — results are alphabetical, so `aabode-spa` comes first in both.

- [ ] **Step 3: Implement ordering**

In `DiscoveryController::__invoke`, replace `$query = Organization::query()->listable()->orderBy('name');` with:

```php
        $query = Organization::query()->listable();
```

Add the name-match rank inside the existing `if ($term !== null) { … }` block, immediately after the `$query->where(function ($match) …);` call:

```php
            // A customer typing a salon's name wants that salon, not every
            // salon that happens to sell a service with a similar name.
            $query
                ->selectRaw(
                    'CASE WHEN LOWER(organizations.name) LIKE ? THEN 0 ELSE 1 END as name_rank',
                    [$like],
                )
                ->orderBy('name_rank');
```

Then, after the whole `if` block and before `$total = …`, add the remaining ordering:

```php
        // A salon that recently took a booking is a salon that still exists.
        // Nulls last: never booked is worse than booked long ago. The boolean
        // expression evaluates to 0/1 on both sqlite and MySQL.
        $query
            ->withMax('appointments', 'created_at')
            ->orderByRaw('appointments_max_created_at IS NULL, appointments_max_created_at DESC')
            ->orderBy('organizations.name');
```

Finally, `$total` must not inherit the select expressions. Replace `$total = (clone $query)->count();` with:

```php
        // Counted off a clean copy: `count()` over the ranking select
        // expressions is both wasteful and, with an ORDER BY on an alias,
        // invalid on MySQL.
        $total = (clone $query)->reorder()->count('organizations.id');
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd backend && php artisan test --filter=DiscoveryTest`
Expected: PASS (18 tests).

- [ ] **Step 5: Run the whole backend suite**

Run: `cd backend && php artisan test`
Expected: PASS — no previously-passing test regresses. (Baseline before this plan: 2 skipped, 422 passed.)

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/Public/DiscoveryController.php backend/tests/Feature/Public/DiscoveryTest.php
git commit -m "feat(discovery): rank name matches and living salons first"
```

---

### Task 5: The search page

**Files:**
- Create: `frontend/src/lib/discovery.js`
- Create: `frontend/src/views/SalonSearchView.vue`
- Create: `frontend/src/views/SalonSearchView.spec.js`

**Interfaces:**
- Consumes: `GET /api/discover/salons?q=&page=` from Tasks 1–4.
- Produces: `searchSalons({ q, page }): Promise<{ data: Array, meta: Object }>` from `@/lib/discovery`; the `SalonSearchView` component (routed in Task 6).

- [ ] **Step 1: Write the API wrapper**

Create `frontend/src/lib/discovery.js`:

```js
import api from '@/lib/api'

/**
 * Salons a customer can book, across every tenant.
 *
 * The endpoint is public and cross-tenant, so no salon slug and no tenant host
 * are involved — unlike every other public call in this app.
 */
export async function searchSalons({ q = '', page = 1 } = {}) {
  const { data } = await api.get('/discover/salons', { params: { q: q || undefined, page } })

  return { data: data.data, meta: data.meta }
}
```

- [ ] **Step 2: Write the failing test**

Create `frontend/src/views/SalonSearchView.spec.js`:

```js
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

vi.mock('@/lib/discovery', () => ({ searchSalons: vi.fn() }))

const replace = vi.fn()
const route = { query: {} }
vi.mock('vue-router', () => ({
  useRouter: () => ({ replace }),
  useRoute: () => route,
  RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' },
}))

import { searchSalons } from '@/lib/discovery'
import SalonSearchView from './SalonSearchView.vue'

const salon = (overrides = {}) => ({
  slug: 'chastity-hyde',
  name: 'Chastity Hyde',
  city: 'Sylhet',
  cover_image_url: null,
  logo_url: null,
  currency: 'BDT',
  price_from: '500.00',
  rating: { average: 4.6, count: 12 },
  services: ['Hair cut', 'Hair spa'],
  ...overrides,
})

const results = (rows) => ({ data: rows, meta: { total: rows.length, page: 1, per_page: 12 } })

describe('SalonSearchView', () => {
  beforeEach(() => {
    vi.useRealTimers()
    replace.mockReset()
    route.query = {}
    vi.mocked(searchSalons).mockReset()
    vi.mocked(searchSalons).mockResolvedValue(results([salon()]))
  })

  it('lists salons and links each card to its shopfront', async () => {
    const wrapper = mount(SalonSearchView)
    await flushPromises()

    expect(wrapper.text()).toContain('Chastity Hyde')
    expect(wrapper.text()).toContain('Sylhet')
    expect(wrapper.find('a[href="/salon/chastity-hyde"]').exists()).toBe(true)
  })

  it('hides the rating of a salon that has too few reviews', async () => {
    vi.mocked(searchSalons).mockResolvedValue(results([salon({ rating: null })]))

    const wrapper = mount(SalonSearchView)
    await flushPromises()

    expect(wrapper.find('[data-test="rating"]').exists()).toBe(false)
  })

  it('starts from the query in the URL', async () => {
    route.query = { q: 'massage' }

    mount(SalonSearchView)
    await flushPromises()

    expect(vi.mocked(searchSalons)).toHaveBeenCalledWith({ q: 'massage', page: 1 })
  })

  it('searches once after typing settles, and puts the query in the URL', async () => {
    vi.useFakeTimers()
    const wrapper = mount(SalonSearchView)
    await flushPromises()
    vi.mocked(searchSalons).mockClear()

    await wrapper.find('input[type="search"]').setValue('hai')
    await wrapper.find('input[type="search"]').setValue('hair')
    vi.advanceTimersByTime(299)
    expect(vi.mocked(searchSalons)).not.toHaveBeenCalled()

    vi.advanceTimersByTime(1)
    expect(vi.mocked(searchSalons)).toHaveBeenCalledTimes(1)
    expect(vi.mocked(searchSalons)).toHaveBeenCalledWith({ q: 'hair', page: 1 })
    expect(replace).toHaveBeenCalledWith({ query: { q: 'hair' } })
  })

  it('tells a searcher when nothing matched, and offers a way back', async () => {
    route.query = { q: 'xyz' }
    vi.mocked(searchSalons).mockResolvedValue({ data: [], meta: { total: 0, page: 1, per_page: 12 } })

    const wrapper = mount(SalonSearchView)
    await flushPromises()

    expect(wrapper.text()).toContain('Nothing matches')
    expect(wrapper.find('[data-test="clear"]').exists()).toBe(true)
  })

  it('is honest when no salon is listed at all', async () => {
    vi.mocked(searchSalons).mockResolvedValue({ data: [], meta: { total: 0, page: 1, per_page: 12 } })

    const wrapper = mount(SalonSearchView)
    await flushPromises()

    expect(wrapper.text()).toContain('just getting started')
    expect(wrapper.find('[data-test="clear"]').exists()).toBe(false)
  })

  it('says so when the search itself fails', async () => {
    vi.mocked(searchSalons).mockRejectedValue(new Error('network'))

    const wrapper = mount(SalonSearchView)
    await flushPromises()

    expect(wrapper.text()).toContain("Couldn't load salons")
  })
})
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `cd frontend && npx vitest run src/views/SalonSearchView.spec.js`
Expected: FAIL — `SalonSearchView.vue` does not exist.

- [ ] **Step 4: Write the view**

Create `frontend/src/views/SalonSearchView.vue`:

```vue
<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import MarketingNav from '@/components/marketing/MarketingNav.vue'
import MarketingFooter from '@/components/marketing/MarketingFooter.vue'
import { searchSalons } from '@/lib/discovery'

const route = useRoute()
const router = useRouter()

// The URL is the source of truth for what was searched, so a result page can
// be shared, reloaded and reached again with the back button.
const q = ref(typeof route.query.q === 'string' ? route.query.q : '')
const salons = ref([])
const total = ref(0)
const loading = ref(true)
const failed = ref(false)

// Set once a search has actually been run with text in the box, so the empty
// state can tell "nothing matched your words" from "nothing is listed yet".
const searched = ref(q.value.trim() !== '')

let timer = null
// Only the newest search may write results: a slow request for "ha" must not
// overwrite the finished one for "hair".
let latest = 0

async function run() {
  const term = q.value.trim()
  const attempt = ++latest

  loading.value = true
  failed.value = false
  searched.value = term !== ''

  try {
    const { data, meta } = await searchSalons({ q: term, page: 1 })
    if (attempt !== latest) return
    salons.value = data
    total.value = meta.total
  } catch {
    if (attempt !== latest) return
    failed.value = true
    salons.value = []
    total.value = 0
  } finally {
    if (attempt === latest) loading.value = false
  }
}

function onInput() {
  clearTimeout(timer)
  timer = setTimeout(() => {
    const term = q.value.trim()
    router.replace({ query: term ? { q: term } : {} })
    run()
  }, 300)
}

function clear() {
  q.value = ''
  clearTimeout(timer)
  router.replace({ query: {} })
  run()
}

function priceLabel(salon) {
  if (!salon.price_from) return null
  // Trim a trailing ".00" — a price list, not a ledger.
  const amount = Number(salon.price_from)
  const shown = Number.isInteger(amount) ? amount.toString() : amount.toFixed(2)
  return `from ${salon.currency} ${shown}`
}

onMounted(run)
onBeforeUnmount(() => clearTimeout(timer))
</script>

<template>
  <div class="bg-paper text-ink min-h-screen">
    <MarketingNav />

    <main class="mx-auto max-w-6xl px-6 py-14 lg:px-8">
      <h1 class="font-display text-4xl font-semibold tracking-tight sm:text-5xl">Find a salon</h1>
      <p class="mt-3 max-w-xl text-ink/60">
        Search by salon, city, or the thing you want done.
      </p>

      <div class="relative mt-8 max-w-xl">
        <input
          v-model="q"
          type="search"
          placeholder="Hair spa, Sylhet, Chastity Hyde…"
          aria-label="Search salons"
          class="w-full rounded-full border border-brand-100 bg-white px-6 py-3.5 text-base text-ink shadow-sm transition-shadow placeholder:text-ink/35 focus-visible:border-brand-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400/30"
          @input="onInput"
        />
      </div>

      <!-- Loading -->
      <div v-if="loading" class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
        <div v-for="n in 6" :key="n" class="animate-pulse rounded-2xl border border-brand-100 bg-white p-5">
          <div class="h-36 rounded-xl bg-brand-50"></div>
          <div class="mt-4 h-4 w-2/3 rounded bg-brand-50"></div>
          <div class="mt-2 h-3 w-1/3 rounded bg-brand-50"></div>
        </div>
      </div>

      <!-- Failed -->
      <p v-else-if="failed" class="mt-12 text-ink/60">
        Couldn't load salons. Check your connection and try again.
      </p>

      <!-- Results -->
      <template v-else-if="salons.length">
        <p class="mt-8 text-sm text-ink/50">{{ total }} {{ total === 1 ? 'salon' : 'salons' }}</p>

        <div class="mt-5 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
          <RouterLink
            v-for="salon in salons"
            :key="salon.slug"
            :to="`/salon/${salon.slug}`"
            class="group block overflow-hidden rounded-2xl border border-brand-100 bg-white shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:shadow-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400"
          >
            <div class="h-40 bg-brand-50">
              <img
                v-if="salon.cover_image_url"
                :src="salon.cover_image_url"
                :alt="salon.name"
                class="h-40 w-full object-cover transition-transform duration-500 group-hover:scale-105"
              />
            </div>

            <div class="p-5">
              <h2 class="font-display text-lg font-semibold text-ink">{{ salon.name }}</h2>
              <p class="mt-1 text-sm text-ink/55">
                <span v-if="salon.city">{{ salon.city }}</span>
                <span v-if="salon.city && priceLabel(salon)" class="text-ink/25"> · </span>
                <span v-if="priceLabel(salon)">{{ priceLabel(salon) }}</span>
              </p>

              <p v-if="salon.rating" data-test="rating" class="mt-2 text-sm text-ink/70">
                ★ {{ salon.rating.average }}
                <span class="text-ink/40">({{ salon.rating.count }})</span>
              </p>

              <ul v-if="salon.services.length" class="mt-4 flex flex-wrap gap-1.5">
                <li
                  v-for="service in salon.services"
                  :key="service"
                  class="rounded-full bg-brand-50 px-2.5 py-1 text-xs text-brand-700"
                >
                  {{ service }}
                </li>
              </ul>
            </div>
          </RouterLink>
        </div>
      </template>

      <!-- Searched, nothing matched -->
      <div v-else-if="searched" class="mt-12">
        <p class="text-ink/60">Nothing matches "{{ q.trim() }}".</p>
        <button
          type="button"
          data-test="clear"
          class="mt-4 rounded-full bg-brand-500 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-brand-600"
          @click="clear"
        >
          Show all salons
        </button>
      </div>

      <!-- Nothing listed at all -->
      <div v-else class="mt-12">
        <p class="text-ink/60">
          SalonHub is just getting started here, so there are no salons to show yet.
        </p>
        <RouterLink
          to="/register"
          class="mt-4 inline-block rounded-full bg-brand-500 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-brand-600"
        >
          Register a salon
        </RouterLink>
      </div>
    </main>

    <MarketingFooter />
  </div>
</template>
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `cd frontend && npx vitest run src/views/SalonSearchView.spec.js`
Expected: PASS (7 tests).

- [ ] **Step 6: Commit**

```bash
git add frontend/src/lib/discovery.js frontend/src/views/SalonSearchView.vue frontend/src/views/SalonSearchView.spec.js
git commit -m "feat(discovery): add the salon search page"
```

---

### Task 6: Route and entry points

A page nobody can reach is not a feature.

**Files:**
- Modify: `frontend/src/router/index.js` (beside the `/terms` record)
- Modify: `frontend/src/components/marketing/MarketingNav.vue`
- Modify: `frontend/src/components/marketing/MarketingFooter.vue`

**Interfaces:**
- Consumes: `SalonSearchView` from Task 5.
- Produces: route named `salons` at `/salons`; "Find a salon" links in the marketing header and footer.

- [ ] **Step 1: Add the route**

In `frontend/src/router/index.js`, add immediately before the `/terms` record:

```js
    {
      // Cross-tenant salon search. Public, and apex-only in practice: a
      // customer already on a salon's subdomain has found their salon.
      path: '/salons',
      name: 'salons',
      component: () => import('@/views/SalonSearchView.vue'),
    },
```

- [ ] **Step 2: Add the header link**

In `frontend/src/components/marketing/MarketingNav.vue`, the desktop anchor links are a `v-for` over `links`, which are all in-page anchors (`#features`), so the search link is added as a sibling rather than an entry in that array. Immediately after the closing `</a>` of that `v-for` block (still inside `<div class="hidden items-center gap-1 md:flex">`), add:

```html
        <RouterLink
          to="/salons"
          class="rounded-full px-3.5 py-2 text-sm font-medium text-ink/65 transition-colors hover:bg-brand-50 hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400"
        >
          Find a salon
        </RouterLink>
```

And in the mobile dropdown panel, immediately after the `v-for` anchor block and before the session/login `RouterLink`, add:

```html
          <RouterLink
            to="/salons"
            class="rounded-xl px-3 py-2.5 text-base font-medium text-ink/80 transition-colors hover:bg-brand-50 hover:text-ink"
            @click="open = false"
          >
            Find a salon
          </RouterLink>
```

- [ ] **Step 3: Add the footer link**

In `frontend/src/components/marketing/MarketingFooter.vue`, inside the "Product" list, add as the first `<li>` (before the `v-for` over `productLinks`):

```html
            <li>
              <RouterLink to="/salons" class="text-paper/70 transition-colors hover:text-paper">
                Find a salon
              </RouterLink>
            </li>
```

- [ ] **Step 4: Verify the build and the whole frontend suite**

Run: `cd frontend && npm run build && npx vitest run`
Expected: build succeeds; all tests pass. (Baseline before this plan: 14 files, 108 tests.)

- [ ] **Step 5: Check it by hand**

Run: `cd frontend && npm run dev`, then visit `http://localhost:5173/salons`.
Expected: the four salons render as cards; typing "hair" narrows them; clearing the box restores them; the URL tracks the query; clicking a card lands on that salon's shopfront; the header and footer links reach the page from `/`.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/router/index.js frontend/src/components/marketing/MarketingNav.vue frontend/src/components/marketing/MarketingFooter.vue
git commit -m "feat(discovery): route /salons and link it from the marketing site"
```

---

## Done when

- `GET /api/discover/salons` returns only salons that can take a booking, matched by name, slug, city or active service, ranked name-match → recent booking → A–Z, 12 per page.
- `/salons` searches as you type, survives reload and the back button, handles all four states, and each card reaches the right shopfront.
- `php artisan test` and `npx vitest run` both pass; `npm run build` succeeds.
