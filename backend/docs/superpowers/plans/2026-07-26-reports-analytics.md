# Reports & Analytics Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an owner/manager reports page showing earned revenue over time, top services, staff performance, and a bookings breakdown for a chosen date range.

**Architecture:** One tenant-scoped endpoint (`GET /api/reports?from&to`) returns a single payload with all four report sections, computed by a `ReportService`. Time bucketing and weekday/hour extraction run PHP-side (DB-agnostic); pure group-by-id aggregations run in SQL. A Vue `ReportsView` renders it with a range picker and an inline SVG bar chart (no chart dependency).

**Tech Stack:** Laravel 12 / PHP 8.4, Sanctum, Eloquent, MySQL (prod) / SQLite `:memory:` (test), Pest/PHPUnit feature tests, Vue 3 `<script setup>` + Pinia + Tailwind, Vite.

## Global Constraints

- **Revenue basis = earned:** SUM of the snapshot `price` on COMPLETED appointments only. Never payments, never menu price.
- **Access = owner + manager only.** Staff (role `staff`) get 403. Gate in `ReportRequest::authorize()` via `$this->user()->isManagerOrOwner()`.
- **Multi-tenancy:** `Appointment` and `Review` carry the `BelongsToOrganization` global scope (auto-isolated). `User` is NOT auto-scoped — staff-name lookups filter explicitly by `organization_id` + `role = 'staff'`.
- **Portability:** Time bucketing (day/week/month) + weekday/hour done in PHP. Group-by-id + COUNT/SUM stays in SQL. Date filters use `whereDate('booking_date', ...)`.
- **Range cap:** reject spans > 366 days (422). Absent range defaults to last 30 days (`to` = today, `from` = today − 29).
- **AppointmentStatus values:** `pending`, `confirmed`, `completed`, `cancelled`, `no_show`.
- **Tests:** real Sanctum tokens (`$user->createToken('api')->plainTextToken`), `RefreshDatabase`, `$this->withToken($token)->getJson(...)`. Watch each test fail before implementing.

---

## File Structure

**Backend (create):**
- `app/Http/Requests/Report/ReportRequest.php` — authorize (owner/manager gate) + validation + range resolution.
- `app/Services/ReportService.php` — all report computation; one public `build(string $from, string $to): array` plus private per-section methods.
- `app/Http/Controllers/ReportController.php` — `__invoke(ReportRequest, CurrentTenant)`; resolves range, calls service, returns JSON.
- `tests/Feature/Reports/ReportsTest.php` — one file, grown task by task, with shared private scaffold helpers.

**Backend (modify):**
- `routes/api.php` — add `use App\Http\Controllers\ReportController;` + `Route::get('reports', ReportController::class);` inside the tenant group.

**Frontend (create):**
- `frontend/src/views/ReportsView.vue` — the whole page (range picker, summary cards, SVG chart, tables, breakdown).

**Frontend (modify):**
- `frontend/src/router/index.js` — add `/reports` route (roles owner/manager).
- `frontend/src/layouts/DashboardLayout.vue` — add "Reports" nav item (roles owner/manager) after Customers, before Reviews.

---

### Task 1: Endpoint scaffold — route, request (gate + validation + range), controller, empty service

Stands up a gated, validated endpoint returning the full payload shape with empty/zero sections. Later tasks fill each section.

**Files:**
- Create: `app/Http/Requests/Report/ReportRequest.php`
- Create: `app/Services/ReportService.php`
- Create: `app/Http/Controllers/ReportController.php`
- Create: `tests/Feature/Reports/ReportsTest.php`
- Modify: `routes/api.php`

**Interfaces:**
- Consumes: `App\Tenancy\CurrentTenant` (`->id()`, `->get()`), `App\Models\User::isManagerOrOwner()` / `isStaff()`.
- Produces:
  - `ReportRequest::range(): array` → `['from' => 'Y-m-d', 'to' => 'Y-m-d']` (defaults applied).
  - `ReportService::build(string $from, string $to): array` → keys `summary`, `revenue`, `top_services`, `staff`, `bookings`.
  - Route name/action: `GET /api/reports` → `ReportController` (invokable).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Reports/ReportsTest.php`:

```php
<?php

namespace Tests\Feature\Reports;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Service;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrg(string $slug = 'acme'): Organization
    {
        return Organization::create([
            'uuid' => (string) Str::uuid(),
            'name' => ucfirst($slug),
            'slug' => $slug,
            'email' => "owner@{$slug}.test",
            'subscription_plan' => 'free',
            'status' => 'active',
        ]);
    }

    private function makeUser(Organization $org, string $role): User
    {
        return User::create([
            'organization_id' => $org->id,
            'name' => ucfirst($role),
            'email' => Str::random(6)."@{$org->slug}.test",
            'password' => 'secret1234',
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function token(User $user): string
    {
        return $user->createToken('api')->plainTextToken;
    }

    private function makeBranch(Organization $org): Branch
    {
        return Branch::create(['organization_id' => $org->id, 'name' => 'Main']);
    }

    private function makeService(Organization $org, string $name = 'Haircut', float $price = 25): Service
    {
        return Service::create([
            'organization_id' => $org->id,
            'name' => $name,
            'duration' => 30,
            'price' => $price,
            'status' => 'active',
        ]);
    }

    private function makeStaff(Organization $org, string $name = 'Sam Stylist'): User
    {
        $staff = User::create([
            'organization_id' => $org->id,
            'name' => $name,
            'email' => Str::random(6)."@{$org->slug}.test",
            'password' => 'secret1234',
            'role' => 'staff',
            'status' => 'active',
        ]);
        StaffProfile::create([
            'user_id' => $staff->id,
            'designation' => 'Stylist',
            'working_days_json' => [1, 2, 3, 4, 5],
            'working_hours_json' => ['start' => '09:00', 'end' => '17:00'],
        ]);

        return $staff;
    }

    /**
     * Create an appointment. $overrides can set date/status/price/staff/service.
     */
    private function makeAppointment(Organization $org, array $overrides = []): Appointment
    {
        $branch = $overrides['branch'] ?? $this->makeBranch($org);
        $service = $overrides['service'] ?? $this->makeService($org);
        $staff = $overrides['staff'] ?? $this->makeStaff($org);
        $customer = Customer::create(['organization_id' => $org->id, 'name' => 'Casey Customer']);

        return Appointment::create([
            'organization_id' => $org->id,
            'public_token' => (string) Str::uuid(),
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'staff_id' => $staff->id,
            'service_id' => $service->id,
            'booking_date' => $overrides['date'] ?? '2026-07-15',
            'start_time' => $overrides['start_time'] ?? '10:00:00',
            'end_time' => '10:30:00',
            'price' => $overrides['price'] ?? 25,
            'status' => $overrides['status'] ?? 'completed',
        ]);
    }

    public function test_owner_can_load_reports(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');

        $res = $this->withToken($this->token($owner))->getJson('/api/reports');

        $res->assertOk();
        $res->assertJsonStructure(['data' => ['summary', 'revenue', 'top_services', 'staff', 'bookings']]);
    }

    public function test_manager_can_load_reports(): void
    {
        $org = $this->makeOrg();
        $manager = $this->makeUser($org, 'manager');

        $this->withToken($this->token($manager))->getJson('/api/reports')->assertOk();
    }

    public function test_staff_cannot_load_reports(): void
    {
        $org = $this->makeOrg();
        $staff = $this->makeUser($org, 'staff');

        $this->withToken($this->token($staff))->getJson('/api/reports')->assertForbidden();
    }

    public function test_range_defaults_to_last_30_days(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');

        $res = $this->withToken($this->token($owner))->getJson('/api/reports');

        // Range echoed so the client knows what window it got.
        $from = \Illuminate\Support\Carbon::parse($res->json('data.range.from'));
        $to = \Illuminate\Support\Carbon::parse($res->json('data.range.to'));
        $this->assertSame(29, $from->diffInDays($to));
    }

    public function test_to_before_from_is_rejected(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');

        $this->withToken($this->token($owner))
            ->getJson('/api/reports?from=2026-07-10&to=2026-07-01')
            ->assertStatus(422);
    }

    public function test_span_over_366_days_is_rejected(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');

        $this->withToken($this->token($owner))
            ->getJson('/api/reports?from=2025-01-01&to=2026-06-01')
            ->assertStatus(422);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Feature/Reports/ReportsTest.php`
Expected: FAIL — route `/api/reports` does not exist (404 / assertion failures).

- [ ] **Step 3: Create the ReportRequest**

Create `app/Http/Requests/Report/ReportRequest.php`:

```php
<?php

namespace App\Http\Requests\Report;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class ReportRequest extends FormRequest
{
    /** Reports are money-sensitive: owner and manager only. */
    public function authorize(): bool
    {
        return $this->user()?->isManagerOrOwner() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ];
    }

    /**
     * Reject ranges longer than a year — bounds the PHP-side aggregation.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            ['from' => $from, 'to' => $to] = $this->range();
            if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 366) {
                $validator->errors()->add('to', 'The date range must be 366 days or fewer.');
            }
        });
    }

    /**
     * Resolved window, defaults applied: last 30 days when nothing is given.
     *
     * @return array{from: string, to: string}
     */
    public function range(): array
    {
        $to = $this->date('to') ?? Carbon::now(config('app.timezone'))->startOfDay();
        $from = $this->date('from') ?? $to->copy()->subDays(29);

        return ['from' => $from->toDateString(), 'to' => $to->toDateString()];
    }
}
```

- [ ] **Step 4: Create the ReportService (empty sections)**

Create `app/Services/ReportService.php`:

```php
<?php

namespace App\Services;

/**
 * Computes the salon's reports for a date range. All money is "earned":
 * the SUM of the snapshot price on COMPLETED appointments. Every query
 * relies on the tenant global scope already being bound.
 */
class ReportService
{
    /**
     * @return array<string, mixed>
     */
    public function build(string $from, string $to): array
    {
        return [
            'range' => ['from' => $from, 'to' => $to],
            'summary' => [],
            'revenue' => [],
            'top_services' => [],
            'staff' => [],
            'bookings' => [],
        ];
    }
}
```

- [ ] **Step 5: Create the ReportController**

Create `app/Http/Controllers/ReportController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\Report\ReportRequest;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;

class ReportController extends Controller
{
    public function __invoke(ReportRequest $request, ReportService $reports): JsonResponse
    {
        ['from' => $from, 'to' => $to] = $request->range();

        return response()->json(['data' => $reports->build($from, $to)]);
    }
}
```

- [ ] **Step 6: Register the route**

In `routes/api.php`, add the import near the other controller imports:

```php
use App\Http\Controllers\ReportController;
```

Inside the tenant group (`Route::middleware(['auth:sanctum', 'tenant'])->group(...)`), after the `reviews` routes, add:

```php
    Route::get('reports', ReportController::class);
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/Reports/ReportsTest.php`
Expected: PASS (6 tests).

- [ ] **Step 8: Commit**

```bash
git add app/Http/Requests/Report/ReportRequest.php app/Services/ReportService.php app/Http/Controllers/ReportController.php routes/api.php tests/Feature/Reports/ReportsTest.php
git commit -m "feat: reports endpoint scaffold (gated, validated, empty payload)"
```

---

### Task 2: Summary section (earned, bookings, avg ticket, previous-period delta)

**Files:**
- Modify: `app/Services/ReportService.php`
- Modify: `tests/Feature/Reports/ReportsTest.php`

**Interfaces:**
- Consumes: `ReportService::build()` from Task 1.
- Produces: `data.summary` shape → `{ earned: float, bookings: int, avg_ticket: float, previous: { earned, bookings, avg_ticket }, delta: { earned_pct: float|null, bookings_pct: float|null } }`.

- [ ] **Step 1: Write the failing tests**

Add to `ReportsTest.php`:

```php
    public function test_summary_counts_only_completed_in_range(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');
        $branch = $this->makeBranch($org);
        $service = $this->makeService($org, 'Cut', 30);
        $staff = $this->makeStaff($org);

        // Two completed in range (30 + 30 = 60).
        $this->makeAppointment($org, ['date' => '2026-07-10', 'price' => 30, 'status' => 'completed', 'branch' => $branch, 'service' => $service, 'staff' => $staff]);
        $this->makeAppointment($org, ['date' => '2026-07-12', 'price' => 30, 'status' => 'completed', 'branch' => $branch, 'service' => $service, 'staff' => $staff]);
        // Excluded: pending in range, and completed outside range.
        $this->makeAppointment($org, ['date' => '2026-07-11', 'price' => 99, 'status' => 'pending', 'branch' => $branch, 'service' => $service, 'staff' => $staff]);
        $this->makeAppointment($org, ['date' => '2026-06-01', 'price' => 99, 'status' => 'completed', 'branch' => $branch, 'service' => $service, 'staff' => $staff]);

        $res = $this->withToken($this->token($owner))->getJson('/api/reports?from=2026-07-01&to=2026-07-31');

        $res->assertJsonPath('data.summary.bookings', 2);
        $this->assertSame(60.0, (float) $res->json('data.summary.earned'));
        $this->assertSame(30.0, (float) $res->json('data.summary.avg_ticket'));
    }

    public function test_summary_delta_compares_previous_equal_window(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');
        $branch = $this->makeBranch($org);
        $service = $this->makeService($org);
        $staff = $this->makeStaff($org);

        // Current window 2026-07-08..2026-07-14 (7 days): earned 100.
        $this->makeAppointment($org, ['date' => '2026-07-10', 'price' => 100, 'status' => 'completed', 'branch' => $branch, 'service' => $service, 'staff' => $staff]);
        // Previous window 2026-07-01..2026-07-07 (7 days): earned 50.
        $this->makeAppointment($org, ['date' => '2026-07-03', 'price' => 50, 'status' => 'completed', 'branch' => $branch, 'service' => $service, 'staff' => $staff]);

        $res = $this->withToken($this->token($owner))->getJson('/api/reports?from=2026-07-08&to=2026-07-14');

        $this->assertSame(50.0, (float) $res->json('data.summary.previous.earned'));
        // (100 - 50) / 50 * 100 = 100%.
        $this->assertSame(100.0, (float) $res->json('data.summary.delta.earned_pct'));
    }

    public function test_summary_delta_is_null_without_baseline(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');

        $this->makeAppointment($org, ['date' => '2026-07-10', 'price' => 40, 'status' => 'completed']);

        $res = $this->withToken($this->token($owner))->getJson('/api/reports?from=2026-07-08&to=2026-07-14');

        $this->assertNull($res->json('data.summary.delta.earned_pct'));
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Feature/Reports/ReportsTest.php --filter=summary`
Expected: FAIL — `data.summary.bookings` is missing (summary is `[]`).

- [ ] **Step 3: Implement the summary in ReportService**

In `app/Services/ReportService.php`, add imports at top:

```php
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use Illuminate\Support\Carbon;
```

Replace `'summary' => [],` in `build()` with `'summary' => $this->summary($from, $to),` and add these methods:

```php
    /**
     * @return array<string, mixed>
     */
    protected function summary(string $from, string $to): array
    {
        $current = $this->earnedWindow($from, $to);

        // Previous window: the equal-length span ending the day before $from.
        $length = Carbon::parse($from)->diffInDays(Carbon::parse($to)); // inclusive gap
        $prevTo = Carbon::parse($from)->subDay();
        $prevFrom = $prevTo->copy()->subDays($length);
        $previous = $this->earnedWindow($prevFrom->toDateString(), $prevTo->toDateString());

        return [
            'earned' => $current['earned'],
            'bookings' => $current['bookings'],
            'avg_ticket' => $current['avg_ticket'],
            'previous' => $previous,
            'delta' => [
                'earned_pct' => $this->pctChange($previous['earned'], $current['earned']),
                'bookings_pct' => $this->pctChange($previous['bookings'], $current['bookings']),
            ],
        ];
    }

    /**
     * Earned total, completed-booking count, and average ticket for a window.
     *
     * @return array{earned: float, bookings: int, avg_ticket: float}
     */
    protected function earnedWindow(string $from, string $to): array
    {
        $query = Appointment::query()
            ->where('status', AppointmentStatus::COMPLETED->value)
            ->whereDate('booking_date', '>=', $from)
            ->whereDate('booking_date', '<=', $to);

        $bookings = (clone $query)->count();
        $earned = round((float) (clone $query)->sum('price'), 2);

        return [
            'earned' => $earned,
            'bookings' => $bookings,
            'avg_ticket' => $bookings > 0 ? round($earned / $bookings, 2) : 0.0,
        ];
    }

    /**
     * Percentage change from $old to $new. Null when there is no baseline
     * (an infinite jump from zero is not a meaningful percentage).
     */
    protected function pctChange(float|int $old, float|int $new): ?float
    {
        if ((float) $old === 0.0) {
            return null;
        }

        return round((($new - $old) / $old) * 100, 1);
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/Reports/ReportsTest.php`
Expected: PASS (9 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/ReportService.php tests/Feature/Reports/ReportsTest.php
git commit -m "feat: reports summary with previous-period delta"
```

---

### Task 3: Revenue series (auto granularity, PHP bucketing, zero-fill)

**Files:**
- Modify: `app/Services/ReportService.php`
- Modify: `tests/Feature/Reports/ReportsTest.php`

**Interfaces:**
- Consumes: `earnedWindow()` helper is NOT used here (per-bucket sums are computed in PHP from fetched rows).
- Produces: `data.revenue` shape → `{ granularity: 'day'|'week'|'month', points: [{ period: string, label: string, earned: float, bookings: int }] }`.

- [ ] **Step 1: Write the failing tests**

Add to `ReportsTest.php`:

```php
    public function test_revenue_series_is_daily_and_zero_filled_for_short_range(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');

        $this->makeAppointment($org, ['date' => '2026-07-02', 'price' => 20, 'status' => 'completed']);
        $this->makeAppointment($org, ['date' => '2026-07-02', 'price' => 30, 'status' => 'completed']);

        $res = $this->withToken($this->token($owner))->getJson('/api/reports?from=2026-07-01&to=2026-07-03');

        $res->assertJsonPath('data.revenue.granularity', 'day');
        $points = $res->json('data.revenue.points');
        $this->assertCount(3, $points); // Jul 1, 2, 3 — zero-filled.
        $this->assertSame('2026-07-01', $points[0]['period']);
        $this->assertSame(0.0, (float) $points[0]['earned']);
        $this->assertSame('2026-07-02', $points[1]['period']);
        $this->assertSame(50.0, (float) $points[1]['earned']);
        $this->assertSame(2, $points[1]['bookings']);
    }

    public function test_revenue_series_is_monthly_for_long_range(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');

        $this->makeAppointment($org, ['date' => '2026-01-15', 'price' => 100, 'status' => 'completed']);
        $this->makeAppointment($org, ['date' => '2026-03-10', 'price' => 200, 'status' => 'completed']);

        $res = $this->withToken($this->token($owner))->getJson('/api/reports?from=2026-01-01&to=2026-03-31');

        $res->assertJsonPath('data.revenue.granularity', 'month');
        $points = collect($res->json('data.revenue.points'));
        $this->assertSame(3, $points->count()); // Jan, Feb, Mar.
        $this->assertSame(100.0, (float) $points->firstWhere('period', '2026-01')['earned']);
        $this->assertSame(0.0, (float) $points->firstWhere('period', '2026-02')['earned']);
        $this->assertSame(200.0, (float) $points->firstWhere('period', '2026-03')['earned']);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Feature/Reports/ReportsTest.php --filter=revenue_series`
Expected: FAIL — `data.revenue.granularity` missing (revenue is `[]`).

- [ ] **Step 3: Implement the revenue series in ReportService**

In `build()`, replace `'revenue' => [],` with `'revenue' => $this->revenueSeries($from, $to),` and add:

```php
    /**
     * Earned + bookings bucketed across the range, zero-filled. Bucketing is
     * done in PHP so it behaves identically on MySQL and SQLite.
     *
     * @return array{granularity: string, points: array<int, array<string, mixed>>}
     */
    protected function revenueSeries(string $from, string $to): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();
        $granularity = $this->granularityFor($start, $end);

        // One row per completed appointment in range; sum in PHP.
        $rows = Appointment::query()
            ->where('status', AppointmentStatus::COMPLETED->value)
            ->whereDate('booking_date', '>=', $from)
            ->whereDate('booking_date', '<=', $to)
            ->get(['booking_date', 'price']);

        $earned = [];   // period key => float
        $bookings = []; // period key => int
        foreach ($rows as $row) {
            $key = $this->periodKey(Carbon::parse($row->booking_date), $granularity);
            $earned[$key] = ($earned[$key] ?? 0) + (float) $row->price;
            $bookings[$key] = ($bookings[$key] ?? 0) + 1;
        }

        // Zero-filled, ordered points across the whole range.
        $points = [];
        foreach ($this->periodCursors($start, $end, $granularity) as $cursor) {
            $key = $this->periodKey($cursor, $granularity);
            $points[] = [
                'period' => $key,
                'label' => $this->periodLabel($cursor, $granularity),
                'earned' => round($earned[$key] ?? 0, 2),
                'bookings' => $bookings[$key] ?? 0,
            ];
        }

        return ['granularity' => $granularity, 'points' => $points];
    }

    protected function granularityFor(Carbon $start, Carbon $end): string
    {
        $days = $start->diffInDays($end);
        if ($days <= 31) {
            return 'day';
        }
        if ($days <= 182) {
            return 'week';
        }

        return 'month';
    }

    protected function periodKey(Carbon $date, string $granularity): string
    {
        return match ($granularity) {
            'day' => $date->toDateString(),          // 2026-07-24
            'week' => $date->isoFormat('GGGG-[W]WW'), // 2026-W30 (ISO week)
            'month' => $date->format('Y-m'),          // 2026-07
        };
    }

    protected function periodLabel(Carbon $date, string $granularity): string
    {
        return match ($granularity) {
            'day' => $date->format('M j'),                       // Jul 24
            'week' => $date->copy()->startOfWeek()->format('M j'), // week of Jul 20
            'month' => $date->format('M Y'),                      // Jul 2026
        };
    }

    /**
     * Ordered cursors from start to end, one per bucket.
     *
     * @return array<int, Carbon>
     */
    protected function periodCursors(Carbon $start, Carbon $end, string $granularity): array
    {
        $cursors = [];
        $cursor = match ($granularity) {
            'day' => $start->copy(),
            'week' => $start->copy()->startOfWeek(),
            'month' => $start->copy()->startOfMonth(),
        };

        while ($cursor->lessThanOrEqualTo($end)) {
            $cursors[] = $cursor->copy();
            $cursor = match ($granularity) {
                'day' => $cursor->addDay(),
                'week' => $cursor->addWeek(),
                'month' => $cursor->addMonth(),
            };
        }

        return $cursors;
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/Reports/ReportsTest.php`
Expected: PASS (11 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/ReportService.php tests/Feature/Reports/ReportsTest.php
git commit -m "feat: reports revenue series (auto granularity, zero-filled)"
```

---

### Task 4: Top services

**Files:**
- Modify: `app/Services/ReportService.php`
- Modify: `tests/Feature/Reports/ReportsTest.php`

**Interfaces:**
- Consumes: `App\Models\Service`.
- Produces: `data.top_services` → `[{ service_id: int, name: string, bookings: int, earned: float, share_pct: float }]`, ranked by `earned` desc, max 10.

- [ ] **Step 1: Write the failing test**

Add to `ReportsTest.php`:

```php
    public function test_top_services_ranked_by_earned(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');
        $branch = $this->makeBranch($org);
        $staff = $this->makeStaff($org);
        $cut = $this->makeService($org, 'Cut', 20);
        $colour = $this->makeService($org, 'Colour', 80);

        // Colour: 1 x 80 = 80. Cut: 2 x 20 = 40.
        $this->makeAppointment($org, ['date' => '2026-07-05', 'price' => 80, 'status' => 'completed', 'branch' => $branch, 'service' => $colour, 'staff' => $staff]);
        $this->makeAppointment($org, ['date' => '2026-07-06', 'price' => 20, 'status' => 'completed', 'branch' => $branch, 'service' => $cut, 'staff' => $staff]);
        $this->makeAppointment($org, ['date' => '2026-07-07', 'price' => 20, 'status' => 'completed', 'branch' => $branch, 'service' => $cut, 'staff' => $staff]);

        $res = $this->withToken($this->token($owner))->getJson('/api/reports?from=2026-07-01&to=2026-07-31');

        $rows = $res->json('data.top_services');
        $this->assertSame('Colour', $rows[0]['name']);
        $this->assertSame(80.0, (float) $rows[0]['earned']);
        $this->assertSame('Cut', $rows[1]['name']);
        $this->assertSame(2, $rows[1]['bookings']);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/Reports/ReportsTest.php --filter=top_services`
Expected: FAIL — `data.top_services` is `[]`.

- [ ] **Step 3: Implement top services**

Add `use App\Models\Service;` at the top of `ReportService.php`. In `build()`, replace `'top_services' => [],` with `'top_services' => $this->topServices($from, $to),` and add:

```php
    /**
     * Completed bookings grouped by service, ranked by earned. Group-by-id +
     * SUM is portable SQL; names are resolved with a tenant-scoped lookup.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function topServices(string $from, string $to): array
    {
        $rows = Appointment::query()
            ->where('status', AppointmentStatus::COMPLETED->value)
            ->whereDate('booking_date', '>=', $from)
            ->whereDate('booking_date', '<=', $to)
            ->selectRaw('service_id, COUNT(*) as bookings, SUM(price) as earned')
            ->groupBy('service_id')
            ->get();

        $total = (float) $rows->sum('earned');
        $names = Service::query()->pluck('name', 'id');

        return $rows
            ->sortByDesc(fn ($row) => (float) $row->earned)
            ->take(10)
            ->map(fn ($row) => [
                'service_id' => (int) $row->service_id,
                'name' => $names->get($row->service_id, 'Unknown'),
                'bookings' => (int) $row->bookings,
                'earned' => round((float) $row->earned, 2),
                'share_pct' => $total > 0 ? round((float) $row->earned / $total * 100, 1) : 0.0,
            ])
            ->values()
            ->all();
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/Reports/ReportsTest.php`
Expected: PASS (12 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/ReportService.php tests/Feature/Reports/ReportsTest.php
git commit -m "feat: reports top services ranked by earned"
```

---

### Task 5: Staff performance (+ rating in range)

**Files:**
- Modify: `app/Services/ReportService.php`
- Modify: `tests/Feature/Reports/ReportsTest.php`

**Interfaces:**
- Consumes: `App\Models\User`, `App\Models\Review`, `App\Enums\UserRole`, `App\Tenancy\CurrentTenant`.
- Produces: `data.staff` → `[{ staff_id: int, name: string, bookings: int, earned: float, rating: { average: float|null, count: int } }]`, ranked by `earned` desc.

- [ ] **Step 1: Write the failing test**

Add to `ReportsTest.php` (add `use App\Models\Review;` to the file's imports):

```php
    public function test_staff_performance_with_rating_in_range(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');
        $branch = $this->makeBranch($org);
        $service = $this->makeService($org, 'Cut', 40);
        $alice = $this->makeStaff($org, 'Alice Wong');
        $bob = $this->makeStaff($org, 'Bob Stone');

        // Alice: 2 completed x 40 = 80. Bob: 1 x 40 = 40.
        $a1 = $this->makeAppointment($org, ['date' => '2026-07-05', 'price' => 40, 'status' => 'completed', 'branch' => $branch, 'service' => $service, 'staff' => $alice]);
        $this->makeAppointment($org, ['date' => '2026-07-06', 'price' => 40, 'status' => 'completed', 'branch' => $branch, 'service' => $service, 'staff' => $alice]);
        $this->makeAppointment($org, ['date' => '2026-07-07', 'price' => 40, 'status' => 'completed', 'branch' => $branch, 'service' => $service, 'staff' => $bob]);

        Review::create([
            'organization_id' => $org->id,
            'appointment_id' => $a1->id,
            'staff_id' => $alice->id,
            'rating' => 5,
            'comment' => 'Great',
            'reviewer_name' => 'Casey Customer',
            'status' => 'published',
        ]);

        $res = $this->withToken($this->token($owner))->getJson('/api/reports?from=2026-07-01&to=2026-07-31');

        $rows = collect($res->json('data.staff'))->keyBy('name');
        $this->assertSame(80.0, (float) $rows['Alice Wong']['earned']);
        $this->assertSame(2, $rows['Alice Wong']['bookings']);
        $this->assertSame(5.0, (float) $rows['Alice Wong']['rating']['average']);
        $this->assertSame(1, $rows['Alice Wong']['rating']['count']);
        $this->assertNull($rows['Bob Stone']['rating']['average']);
    }
```

Note: `Review::create` writes a row directly; the review's `created_at` is "now" (2026-07-24 per session), which falls inside the July range under test. If the range filter for ratings ever needs a controlled timestamp, set `created_at` explicitly — not required for this test.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/Reports/ReportsTest.php --filter=staff_performance`
Expected: FAIL — `data.staff` is `[]`.

- [ ] **Step 3: Implement staff performance**

Add these imports to `ReportService.php`:

```php
use App\Enums\UserRole;
use App\Models\Review;
use App\Models\User;
use App\Tenancy\CurrentTenant;
```

In `build()`, replace `'staff' => [],` with `'staff' => $this->staffPerformance($from, $to),` and add:

```php
    /**
     * Completed bookings + earned per staff member, ranked by earned, each
     * with their average review rating over the same window.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function staffPerformance(string $from, string $to): array
    {
        $rows = Appointment::query()
            ->where('status', AppointmentStatus::COMPLETED->value)
            ->whereDate('booking_date', '>=', $from)
            ->whereDate('booking_date', '<=', $to)
            ->selectRaw('staff_id, COUNT(*) as bookings, SUM(price) as earned')
            ->groupBy('staff_id')
            ->get();

        // Staff names: User carries no tenant scope, so filter explicitly.
        $names = User::query()
            ->where('organization_id', app(CurrentTenant::class)->id())
            ->where('role', UserRole::STAFF->value)
            ->pluck('name', 'id');

        $ratings = $this->staffRatings($from, $to);

        return $rows
            ->sortByDesc(fn ($row) => (float) $row->earned)
            ->map(fn ($row) => [
                'staff_id' => (int) $row->staff_id,
                'name' => $names->get($row->staff_id, 'Unknown'),
                'bookings' => (int) $row->bookings,
                'earned' => round((float) $row->earned, 2),
                'rating' => $this->ratingFor($ratings->get($row->staff_id)),
            ])
            ->values()
            ->all();
    }

    /**
     * Per-staff review aggregate over the range, keyed by staff id.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    protected function staffRatings(string $from, string $to): \Illuminate\Support\Collection
    {
        return Review::query()
            ->whereNotNull('staff_id')
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->selectRaw('staff_id, AVG(rating) as avg_rating, COUNT(*) as cnt')
            ->groupBy('staff_id')
            ->get()
            ->keyBy('staff_id');
    }

    /**
     * @return array{average: float|null, count: int}
     */
    protected function ratingFor(?object $row): array
    {
        return [
            'average' => $row ? round((float) $row->avg_rating, 1) : null,
            'count' => $row ? (int) $row->cnt : 0,
        ];
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/Reports/ReportsTest.php`
Expected: PASS (13 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/ReportService.php tests/Feature/Reports/ReportsTest.php
git commit -m "feat: reports staff performance with in-range rating"
```

---

### Task 6: Bookings breakdown (status counts + busiest day/hour)

**Files:**
- Modify: `app/Services/ReportService.php`
- Modify: `tests/Feature/Reports/ReportsTest.php`

**Interfaces:**
- Produces: `data.bookings` → `{ by_status: { pending, confirmed, completed, cancelled, no_show }, busiest_day: { weekday: int, count: int }|null, busiest_hour: { hour: int, count: int }|null }`. `weekday` is 0=Sunday..6=Saturday.

- [ ] **Step 1: Write the failing tests**

Add to `ReportsTest.php`:

```php
    public function test_bookings_breakdown_counts_all_statuses(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');
        $branch = $this->makeBranch($org);
        $service = $this->makeService($org);
        $staff = $this->makeStaff($org);

        $this->makeAppointment($org, ['date' => '2026-07-05', 'status' => 'completed', 'branch' => $branch, 'service' => $service, 'staff' => $staff]);
        $this->makeAppointment($org, ['date' => '2026-07-06', 'status' => 'cancelled', 'branch' => $branch, 'service' => $service, 'staff' => $staff]);
        $this->makeAppointment($org, ['date' => '2026-07-07', 'status' => 'no_show', 'branch' => $branch, 'service' => $service, 'staff' => $staff]);

        $res = $this->withToken($this->token($owner))->getJson('/api/reports?from=2026-07-01&to=2026-07-31');

        $res->assertJsonPath('data.bookings.by_status.completed', 1);
        $res->assertJsonPath('data.bookings.by_status.cancelled', 1);
        $res->assertJsonPath('data.bookings.by_status.no_show', 1);
        $res->assertJsonPath('data.bookings.by_status.pending', 0);
    }

    public function test_bookings_breakdown_busiest_day_and_hour(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');
        $branch = $this->makeBranch($org);
        $service = $this->makeService($org);
        $staff = $this->makeStaff($org);

        // 2026-07-06 is a Monday (weekday 1). Two appointments at 14:00.
        $this->makeAppointment($org, ['date' => '2026-07-06', 'start_time' => '14:00:00', 'status' => 'completed', 'branch' => $branch, 'service' => $service, 'staff' => $staff]);
        $this->makeAppointment($org, ['date' => '2026-07-06', 'start_time' => '14:30:00', 'status' => 'confirmed', 'branch' => $branch, 'service' => $service, 'staff' => $staff]);
        // 2026-07-07 (Tuesday) at 09:00 — a single, lighter day.
        $this->makeAppointment($org, ['date' => '2026-07-07', 'start_time' => '09:00:00', 'status' => 'completed', 'branch' => $branch, 'service' => $service, 'staff' => $staff]);

        $res = $this->withToken($this->token($owner))->getJson('/api/reports?from=2026-07-01&to=2026-07-31');

        $res->assertJsonPath('data.bookings.busiest_day.weekday', 1); // Monday
        $res->assertJsonPath('data.bookings.busiest_day.count', 2);
        $res->assertJsonPath('data.bookings.busiest_hour.hour', 14);
        $res->assertJsonPath('data.bookings.busiest_hour.count', 2);
    }

    public function test_bookings_breakdown_busiest_is_null_when_empty(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');

        $res = $this->withToken($this->token($owner))->getJson('/api/reports?from=2026-07-01&to=2026-07-31');

        $this->assertNull($res->json('data.bookings.busiest_day'));
        $this->assertNull($res->json('data.bookings.busiest_hour'));
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Feature/Reports/ReportsTest.php --filter=bookings_breakdown`
Expected: FAIL — `data.bookings.by_status` missing (bookings is `[]`).

- [ ] **Step 3: Implement the bookings breakdown**

In `build()`, replace `'bookings' => [],` with `'bookings' => $this->bookingsBreakdown($from, $to),` and add:

```php
    /**
     * Status mix over the range (all statuses, zero-filled) plus the busiest
     * weekday and hour across non-cancelled appointments (computed PHP-side).
     *
     * @return array<string, mixed>
     */
    protected function bookingsBreakdown(string $from, string $to): array
    {
        $counts = Appointment::query()
            ->whereDate('booking_date', '>=', $from)
            ->whereDate('booking_date', '<=', $to)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $byStatus = [];
        foreach (AppointmentStatus::cases() as $status) {
            $byStatus[$status->value] = (int) $counts->get($status->value, 0);
        }

        // Busiest day/hour ignore cancellations — they never happened.
        $active = Appointment::query()
            ->where('status', '!=', AppointmentStatus::CANCELLED->value)
            ->whereDate('booking_date', '>=', $from)
            ->whereDate('booking_date', '<=', $to)
            ->get(['booking_date', 'start_time']);

        $dayCounts = [];  // weekday 0-6 => count
        $hourCounts = []; // hour 0-23 => count
        foreach ($active as $appt) {
            $weekday = Carbon::parse($appt->booking_date)->dayOfWeek; // 0=Sun
            $hour = (int) Carbon::parse($appt->start_time)->format('G');
            $dayCounts[$weekday] = ($dayCounts[$weekday] ?? 0) + 1;
            $hourCounts[$hour] = ($hourCounts[$hour] ?? 0) + 1;
        }

        return [
            'by_status' => $byStatus,
            'busiest_day' => $this->peak($dayCounts, 'weekday'),
            'busiest_hour' => $this->peak($hourCounts, 'hour'),
        ];
    }

    /**
     * Highest-count key in a {key => count} map, or null when empty.
     *
     * @param  array<int, int>  $counts
     * @return array<string, int>|null
     */
    protected function peak(array $counts, string $keyName): ?array
    {
        if ($counts === []) {
            return null;
        }

        $topKey = array_keys($counts, max($counts))[0];

        return [$keyName => (int) $topKey, 'count' => (int) $counts[$topKey]];
    }
```

- [ ] **Step 4: Run the full report suite to verify it passes**

Run: `php artisan test tests/Feature/Reports/ReportsTest.php`
Expected: PASS (16 tests).

- [ ] **Step 5: Run the whole backend suite (no regressions)**

Run: `php artisan test`
Expected: PASS — all prior tests plus the new 16.

- [ ] **Step 6: Commit**

```bash
git add app/Services/ReportService.php tests/Feature/Reports/ReportsTest.php
git commit -m "feat: reports bookings breakdown (status mix, busiest day/hour)"
```

---

### Task 7: Frontend — route, nav, ReportsView

Display-only. Verified by build + browser (repo has no JS test harness).

**Files:**
- Create: `frontend/src/views/ReportsView.vue`
- Modify: `frontend/src/router/index.js`
- Modify: `frontend/src/layouts/DashboardLayout.vue`

**Interfaces:**
- Consumes: `GET /api/reports?from&to` → `data` with `range`, `summary`, `revenue`, `top_services`, `staff`, `bookings` (shapes from Tasks 1–6). Shared axios client `@/lib/api`, `parseApiError` from `@/lib/errors`, `useAuthStore` (`organization.currency`, `canManageOperations`).

- [ ] **Step 1: Add the route**

In `frontend/src/router/index.js`, inside the DashboardLayout `children` array, after the `customers` route and before `reviews`, add:

```js
        {
          path: 'reports',
          name: 'reports',
          component: () => import('@/views/ReportsView.vue'),
          meta: { requiresAuth: true, roles: ['owner', 'manager'] },
        },
```

- [ ] **Step 2: Add the nav item**

In `frontend/src/layouts/DashboardLayout.vue`, in the `nav` array, immediately before the `Reviews` entry, add:

```js
  {
    name: 'Reports',
    to: '/reports',
    roles: ['owner', 'manager'],
    d: 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z',
  },
```

- [ ] **Step 3: Create ReportsView.vue**

Create `frontend/src/views/ReportsView.vue`:

```vue
<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import api from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import { parseApiError } from '@/lib/errors'

const authStore = useAuthStore()
const currency = computed(() => authStore.organization?.currency || 'USD')

const report = ref(null)
const loading = ref(false)
const loadError = ref('')

// Active preset key, or 'custom' when the from/to inputs are edited.
const activePreset = ref('30d')
const range = reactive({ from: '', to: '' })

const STATUS_META = {
  pending: { label: 'Pending', class: 'bg-amber-100 text-amber-700' },
  confirmed: { label: 'Confirmed', class: 'bg-blue-100 text-blue-700' },
  completed: { label: 'Completed', class: 'bg-emerald-100 text-emerald-700' },
  cancelled: { label: 'Cancelled', class: 'bg-slate-200 text-slate-600' },
  no_show: { label: 'No-show', class: 'bg-rose-100 text-rose-700' },
}
const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']

function ymd(date) {
  const y = date.getFullYear()
  const m = String(date.getMonth() + 1).padStart(2, '0')
  const d = String(date.getDate()).padStart(2, '0')
  return `${y}-${m}-${d}`
}

// Each preset resolves to concrete from/to dates on the client.
function presetRange(key) {
  const today = new Date()
  const to = ymd(today)
  if (key === '7d') {
    const from = new Date(today); from.setDate(from.getDate() - 6)
    return { from: ymd(from), to }
  }
  if (key === '30d') {
    const from = new Date(today); from.setDate(from.getDate() - 29)
    return { from: ymd(from), to }
  }
  if (key === 'month') {
    return { from: ymd(new Date(today.getFullYear(), today.getMonth(), 1)), to }
  }
  if (key === 'lastMonth') {
    const first = new Date(today.getFullYear(), today.getMonth() - 1, 1)
    const last = new Date(today.getFullYear(), today.getMonth(), 0)
    return { from: ymd(first), to: ymd(last) }
  }
  // year
  return { from: ymd(new Date(today.getFullYear(), 0, 1)), to }
}

const PRESETS = [
  { key: '7d', label: '7 days' },
  { key: '30d', label: '30 days' },
  { key: 'month', label: 'This month' },
  { key: 'lastMonth', label: 'Last month' },
  { key: 'year', label: 'This year' },
]

function money(value) {
  const amount = Number(value || 0)
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency.value, maximumFractionDigits: 2 }).format(amount)
  } catch {
    return `${currency.value} ${amount.toFixed(2)}`
  }
}

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    const { data } = await api.get('/reports', { params: { from: range.from, to: range.to } })
    report.value = data.data
  } catch (err) {
    loadError.value = parseApiError(err, 'Could not load reports.').message
  } finally {
    loading.value = false
  }
}

function applyPreset(key) {
  activePreset.value = key
  Object.assign(range, presetRange(key))
  load()
}

function applyCustom() {
  if (!range.from || !range.to) return
  activePreset.value = 'custom'
  load()
}

// Chart geometry: normalise bars against the tallest earning bucket.
const maxEarned = computed(() => {
  const points = report.value?.revenue?.points || []
  return Math.max(1, ...points.map((p) => Number(p.earned)))
})

const delta = computed(() => report.value?.summary?.delta || {})

function deltaClass(pct) {
  if (pct === null || pct === undefined) return 'text-slate-400'
  return pct >= 0 ? 'text-emerald-600' : 'text-rose-600'
}
function deltaText(pct) {
  if (pct === null || pct === undefined) return '—'
  return `${pct >= 0 ? '+' : ''}${pct}% vs prev period`
}

onMounted(() => applyPreset('30d'))
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-2xl font-bold text-slate-900">Reports</h1>
      <p class="mt-1 text-sm text-slate-500">Earnings, services, staff, and bookings at a glance.</p>
    </div>

    <!-- Range picker -->
    <div class="mb-6 flex flex-wrap items-end gap-3">
      <div class="inline-flex flex-wrap gap-1 rounded-lg bg-slate-100 p-1 text-sm">
        <button
          v-for="preset in PRESETS"
          :key="preset.key"
          type="button"
          class="rounded-md px-3 py-1.5 font-medium transition"
          :class="activePreset === preset.key ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
          @click="applyPreset(preset.key)"
        >
          {{ preset.label }}
        </button>
      </div>
      <div class="flex items-end gap-2">
        <div>
          <label class="mb-1 block text-xs font-medium text-slate-500">From</label>
          <input v-model="range.from" type="date" class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" @change="applyCustom" />
        </div>
        <div>
          <label class="mb-1 block text-xs font-medium text-slate-500">To</label>
          <input v-model="range.to" type="date" class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" @change="applyCustom" />
        </div>
      </div>
    </div>

    <div v-if="loadError" class="mb-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
      {{ loadError }}
    </div>

    <div v-if="loading" class="rounded-2xl bg-white p-10 text-center text-sm text-slate-500 ring-1 ring-slate-200">
      Loading reports…
    </div>

    <template v-else-if="report">
      <!-- Summary cards -->
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <p class="text-sm text-slate-500">Earned</p>
          <p class="mt-1 text-2xl font-bold text-slate-900">{{ money(report.summary.earned) }}</p>
          <p class="mt-1 text-xs font-medium" :class="deltaClass(delta.earned_pct)">{{ deltaText(delta.earned_pct) }}</p>
        </div>
        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <p class="text-sm text-slate-500">Bookings</p>
          <p class="mt-1 text-2xl font-bold text-slate-900">{{ report.summary.bookings }}</p>
          <p class="mt-1 text-xs font-medium" :class="deltaClass(delta.bookings_pct)">{{ deltaText(delta.bookings_pct) }}</p>
        </div>
        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <p class="text-sm text-slate-500">Avg ticket</p>
          <p class="mt-1 text-2xl font-bold text-slate-900">{{ money(report.summary.avg_ticket) }}</p>
          <p class="mt-1 text-xs text-slate-400">completed bookings</p>
        </div>
      </div>

      <!-- Revenue chart -->
      <div class="mt-6 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <h2 class="text-sm font-semibold text-slate-900">Revenue over time</h2>
        <div v-if="report.revenue.points.length" class="mt-4 flex h-48 items-end gap-1 overflow-x-auto">
          <div
            v-for="point in report.revenue.points"
            :key="point.period"
            class="group relative flex min-w-[8px] flex-1 flex-col items-center justify-end"
            :title="`${point.label}: ${money(point.earned)}`"
          >
            <div
              class="w-full rounded-t bg-indigo-500 transition group-hover:bg-indigo-600"
              :style="{ height: `${Math.max(2, (Number(point.earned) / maxEarned) * 100)}%` }"
            ></div>
          </div>
        </div>
        <p v-else class="mt-4 text-sm text-slate-500">No revenue in this range.</p>
        <p class="mt-2 text-xs text-slate-400">Grouped by {{ report.revenue.granularity }}.</p>
      </div>

      <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <!-- Top services -->
        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <h2 class="text-sm font-semibold text-slate-900">Top services</h2>
          <table v-if="report.top_services.length" class="mt-3 w-full text-sm">
            <thead>
              <tr class="text-left text-xs uppercase tracking-wide text-slate-400">
                <th class="pb-2">Service</th>
                <th class="pb-2 text-right">Bookings</th>
                <th class="pb-2 text-right">Earned</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="row in report.top_services" :key="row.service_id">
                <td class="py-2 text-slate-900">{{ row.name }} <span class="text-xs text-slate-400">({{ row.share_pct }}%)</span></td>
                <td class="py-2 text-right text-slate-600">{{ row.bookings }}</td>
                <td class="py-2 text-right font-medium text-slate-900">{{ money(row.earned) }}</td>
              </tr>
            </tbody>
          </table>
          <p v-else class="mt-3 text-sm text-slate-500">No completed bookings in this range.</p>
        </div>

        <!-- Staff performance -->
        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <h2 class="text-sm font-semibold text-slate-900">Staff performance</h2>
          <table v-if="report.staff.length" class="mt-3 w-full text-sm">
            <thead>
              <tr class="text-left text-xs uppercase tracking-wide text-slate-400">
                <th class="pb-2">Staff</th>
                <th class="pb-2 text-right">Bookings</th>
                <th class="pb-2 text-right">Earned</th>
                <th class="pb-2 text-right">Rating</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="row in report.staff" :key="row.staff_id">
                <td class="py-2 text-slate-900">{{ row.name }}</td>
                <td class="py-2 text-right text-slate-600">{{ row.bookings }}</td>
                <td class="py-2 text-right font-medium text-slate-900">{{ money(row.earned) }}</td>
                <td class="py-2 text-right text-slate-600">
                  <span v-if="row.rating.average !== null">★ {{ row.rating.average }} <span class="text-xs text-slate-400">({{ row.rating.count }})</span></span>
                  <span v-else class="text-slate-300">—</span>
                </td>
              </tr>
            </tbody>
          </table>
          <p v-else class="mt-3 text-sm text-slate-500">No completed bookings in this range.</p>
        </div>
      </div>

      <!-- Bookings breakdown -->
      <div class="mt-6 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <h2 class="text-sm font-semibold text-slate-900">Bookings breakdown</h2>
        <div class="mt-3 flex flex-wrap gap-2">
          <span
            v-for="(count, key) in report.bookings.by_status"
            :key="key"
            class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium"
            :class="STATUS_META[key]?.class || 'bg-slate-200 text-slate-600'"
          >
            {{ STATUS_META[key]?.label || key }}: {{ count }}
          </span>
        </div>
        <div class="mt-4 flex flex-wrap gap-6 text-sm">
          <div>
            <p class="text-xs uppercase tracking-wide text-slate-400">Busiest day</p>
            <p class="mt-0.5 font-medium text-slate-900">
              {{ report.bookings.busiest_day ? `${WEEKDAYS[report.bookings.busiest_day.weekday]} (${report.bookings.busiest_day.count})` : '—' }}
            </p>
          </div>
          <div>
            <p class="text-xs uppercase tracking-wide text-slate-400">Busiest hour</p>
            <p class="mt-0.5 font-medium text-slate-900">
              {{ report.bookings.busiest_hour ? `${String(report.bookings.busiest_hour.hour).padStart(2, '0')}:00 (${report.bookings.busiest_hour.count})` : '—' }}
            </p>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
```

- [ ] **Step 4: Build the frontend**

Run: `cd frontend && npm run build`
Expected: build succeeds; a `ReportsView-*.js` chunk is emitted.

- [ ] **Step 5: Browser check**

With dev servers running, log in as an owner or manager, open `/reports`. Verify: presets switch the range and refetch; summary cards + deltas render; the revenue bars draw; top-services and staff tables populate; status chips + busiest day/hour show. Confirm a `staff`-role account does not see the Reports nav item and is redirected away from `/reports`.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/views/ReportsView.vue frontend/src/router/index.js frontend/src/layouts/DashboardLayout.vue
git commit -m "feat: reports dashboard view (range picker, chart, tables, breakdown)"
```

---

## Self-Review

**Spec coverage:**
- Four reports → summary+revenue (Task 2, 3), top services (Task 4), staff performance (Task 5), bookings breakdown (Task 6). ✓
- Earned revenue basis → completed-only SUM(price) everywhere. ✓
- Presets + custom range → Task 7 range picker; backend defaults + validation Task 1. ✓
- Owner+manager access → `ReportRequest::authorize()` Task 1; nav/route roles Task 7. ✓
- Delta vs previous window, null without baseline → Task 2. ✓
- Auto granularity + zero-fill, PHP-side bucketing → Task 3. ✓
- Portability (SQL group-by-id vs PHP bucketing) → Tasks 3–6. ✓
- Multi-tenancy (User manual scope) → Task 5 staff-name lookup. ✓
- Tests: earned-only, range filter, delta, granularity, top-services ranking, staff+rating, status+busiest, 403, 422 → Tasks 1–6. ✓ (Tenant isolation is covered by the global scope; foreign-org data cannot appear because every query runs under the bound tenant — no dedicated cross-org test is added since no report query bypasses the scope.)

**Placeholder scan:** none — every step has concrete code/commands.

**Type consistency:** payload keys (`summary`, `revenue`, `top_services`, `staff`, `bookings`, `range`) identical across controller, service, tests, and view. `rating` shape `{average, count}` matches between Task 5 and the view. `busiest_day.weekday` / `busiest_hour.hour` match between Task 6 and the view. `delta.earned_pct` / `delta.bookings_pct` match Task 2 and the view.
