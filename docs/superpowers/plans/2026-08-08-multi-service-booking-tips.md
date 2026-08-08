# Multi-Service Booking & Tips Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a customer book several services in one appointment, and let staff record a tip when they take payment at checkout.

**Architecture:** Services become snapshotted line items in a new `appointment_services` table. `appointments.price` stays as the denormalized total and `end_time` as start plus the summed durations, so every existing money and calendar consumer keeps working. One staff member performs the whole visit back-to-back. Tips live in a new `payments.tip_amount` column, kept out of the booking balance and paid to the stylist in full through a new `payroll_lines.tips_amount`.

**Tech Stack:** Laravel 12 / PHP 8.4, MySQL (SQLite in tests), Pest-style PHPUnit feature tests, Vue 3 + Pinia + Tailwind, Vitest.

**Spec:** `docs/superpowers/specs/2026-08-08-multi-service-booking-tips-design.md`

## Global Constraints

- Backend commands run from `backend/`; frontend commands from `frontend/`.
- Backend tests: `php artisan test`. A single file: `php artisan test tests/Feature/Path/FileTest.php`.
- Frontend tests: `npm run test:unit`. A single file: `npm run test:unit -- src/views/File.spec.js`.
- Every model that is reachable directly from a controller carries `BelongsToOrganization`. `AppointmentService` does **not** — it is only ever reached through its appointment, exactly like `PayrollLine` is only reached through its run.
- Every `Rule::exists()` on a tenant-owned table must be constrained with `->where('organization_id', $tenantId)`. Never accept a bare id.
- Money columns are `decimal(10,2)`; money returned from the API is a string formatted with `number_format($v, 2, '.', '')`.
- Times are stored `H:i:s` and returned `H:i`.
- Follow the existing comment style: comments explain *why*, never *what*.
- Commit after every task. Conventional Commits (`feat:`, `fix:`, `test:`, `refactor:`).
- Do not add a compatibility shim for the old single-`service_id` API shape. Backend and frontend change together.

## File Structure

**Create**
- `backend/database/migrations/2026_08_09_100000_create_appointment_services_table.php` — line-item table.
- `backend/database/migrations/2026_08_09_100100_backfill_appointment_services.php` — one line per existing appointment.
- `backend/database/migrations/2026_08_09_100200_drop_service_id_from_appointments_table.php` — runs only after all code is off the column.
- `backend/database/migrations/2026_08_09_100300_add_tip_amount_to_payments_table.php`
- `backend/database/migrations/2026_08_09_100400_add_tips_amount_to_payroll_lines_table.php`
- `backend/app/Models/AppointmentService.php` — the line-item model.
- `backend/database/factories/AppointmentServiceFactory.php`
- `backend/app/Actions/AppointmentServiceWriter.php` — the only place lines and totals are written.
- `backend/tests/Feature/Booking/AppointmentServiceLineTest.php`
- `backend/tests/Feature/Booking/MultiServiceBookingTest.php`
- `backend/tests/Feature/Migrations/AppointmentServiceBackfillTest.php`
- `backend/tests/Feature/Crud/PaymentTipTest.php`

**Modify**
- `backend/app/Models/Appointment.php` — `lines()` / `services()` relations, drop `service()` and the `service_id` fillable.
- `backend/app/Models/Service.php` — `appointments()` becomes a `belongsToMany` through the line table.
- `backend/app/Models/Payment.php`, `backend/app/Models/PayrollLine.php` — new columns.
- `backend/app/Services/SlotGenerator.php` — takes a duration, not a `Service`.
- `backend/app/Services/PayrollCalculator.php` — tips aggregate.
- `backend/app/Services/ReportService.php` — `topServices` over line items.
- `backend/app/Services/BookingNotifier.php` — eager-load `lines` instead of `service`.
- `backend/app/Http/Controllers/AppointmentController.php`, `Public/BookingController.php`, `Customer/BookingController.php`, `InvoiceController.php`, `ServiceController.php`, `PayrollLineController.php`.
- `backend/app/Http/Requests/Appointment/{Store,Update}AppointmentRequest.php`, `Public/PublicBookingRequest.php`, `Payment/StorePaymentRequest.php`.
- `backend/app/Http/Resources/{Appointment,Payment,PayrollLine,ReviewAdmin}Resource.php`.
- `backend/resources/views/mail/booking/{customer,salon,cancelled,rescheduled}.blade.php`.
- `backend/routes/api.php` — the staff-for-services endpoint.
- `frontend/src/views/{PublicBookingView,AppointmentsView,ManageBookingView,ReportsView}.vue`, plus the payroll view.

---

### Task 1: The `appointment_services` line-item table and model

**Files:**
- Create: `backend/database/migrations/2026_08_09_100000_create_appointment_services_table.php`
- Create: `backend/app/Models/AppointmentService.php`
- Create: `backend/database/factories/AppointmentServiceFactory.php`
- Modify: `backend/app/Models/Appointment.php`
- Test: `backend/tests/Feature/Booking/AppointmentServiceLineTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `AppointmentService` model with `$fillable = ['appointment_id','service_id','name','price','duration','sort_order']`; `Appointment::lines(): HasMany` ordered by `sort_order`; `Appointment::services(): BelongsToMany`.

- [ ] **Step 1: Write the failing test**

`backend/tests/Feature/Booking/AppointmentServiceLineTest.php`:

```php
<?php

namespace Tests\Feature\Booking;

use App\Models\Appointment;
use App\Models\AppointmentService;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AppointmentServiceLineTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{org: Organization, appointment: Appointment, service: Service} */
    private function scaffold(): array
    {
        $org = Organization::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Acme', 'slug' => 'acme',
            'email' => 'owner@acme.test', 'subscription_plan' => 'free', 'status' => 'active',
        ]);
        $staff = User::create([
            'organization_id' => $org->id, 'name' => 'Stylist', 'email' => 'stylist@acme.test',
            'password' => 'secret1234', 'role' => 'staff', 'status' => 'active',
        ]);
        $branch = Branch::create(['organization_id' => $org->id, 'name' => 'Main']);
        $customer = Customer::create([
            'organization_id' => $org->id, 'name' => 'Casey', 'phone' => '+15550100',
        ]);
        $service = Service::create([
            'organization_id' => $org->id, 'name' => 'Haircut',
            'duration' => 30, 'price' => 40, 'status' => 'active',
        ]);
        $appointment = Appointment::create([
            'organization_id' => $org->id, 'branch_id' => $branch->id,
            'customer_id' => $customer->id, 'staff_id' => $staff->id,
            // Still required and still cascading until Task 8 drops it.
            'service_id' => $service->id,
            'booking_date' => '2026-09-01', 'start_time' => '10:00:00',
            'end_time' => '10:30:00', 'price' => 40, 'status' => 'pending',
        ]);

        return ['org' => $org, 'appointment' => $appointment, 'service' => $service];
    }

    public function test_lines_come_back_in_sort_order(): void
    {
        ['appointment' => $appointment, 'service' => $service] = $this->scaffold();

        AppointmentService::create([
            'appointment_id' => $appointment->id, 'service_id' => $service->id,
            'name' => 'Blow Dry', 'price' => 15, 'duration' => 20, 'sort_order' => 1,
        ]);
        AppointmentService::create([
            'appointment_id' => $appointment->id, 'service_id' => $service->id,
            'name' => 'Haircut', 'price' => 40, 'duration' => 30, 'sort_order' => 0,
        ]);

        $names = $appointment->fresh()->lines->pluck('name')->all();

        $this->assertSame(['Haircut', 'Blow Dry'], $names);
    }

    public function test_a_line_survives_its_service_being_removed(): void
    {
        ['org' => $org, 'appointment' => $appointment] = $this->scaffold();

        // A service the appointment's own legacy service_id does not point at,
        // so this deletion exercises the line's nullOnDelete rather than the
        // cascade still hanging off appointments.service_id until Task 8.
        $extra = Service::create([
            'organization_id' => $org->id, 'name' => 'Blow Dry',
            'duration' => 20, 'price' => 15, 'status' => 'active',
        ]);

        $line = AppointmentService::create([
            'appointment_id' => $appointment->id, 'service_id' => $extra->id,
            'name' => 'Blow Dry', 'price' => 15, 'duration' => 20, 'sort_order' => 1,
        ]);

        // Bypasses ServiceController's refusal on purpose: the column's own
        // nullOnDelete is what guarantees history survives any future path.
        DB::table('services')->where('id', $extra->id)->delete();

        $line->refresh();

        $this->assertNull($line->service_id);
        $this->assertSame('Blow Dry', $line->name);
        $this->assertSame('15.00', $line->price);
    }

    public function test_deleting_the_appointment_takes_its_lines(): void
    {
        ['appointment' => $appointment, 'service' => $service] = $this->scaffold();

        AppointmentService::create([
            'appointment_id' => $appointment->id, 'service_id' => $service->id,
            'name' => 'Haircut', 'price' => 40, 'duration' => 30, 'sort_order' => 0,
        ]);

        $appointment->delete();

        $this->assertDatabaseCount('appointment_services', 0);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd backend && php artisan test tests/Feature/Booking/AppointmentServiceLineTest.php`
Expected: FAIL — `Class "App\Models\AppointmentService" not found`.

- [ ] **Step 3: Write the migration**

`backend/database/migrations/2026_08_09_100000_create_appointment_services_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One service on one visit. name/price/duration are snapshots taken at
     * booking time for the same reason appointments.price is: the invoice must
     * show what was quoted, not a menu that has moved since. Because the
     * snapshot stands on its own, service_id is nullOnDelete — losing the menu
     * row must never cost the salon its visit history.
     *
     * No organization_id and no tenant scope: a line is only ever reached
     * through its appointment, which is scoped.
     */
    public function up(): void
    {
        Schema::create('appointment_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->string('name');
            $table->decimal('price', 10, 2)->default(0);
            $table->unsignedInteger('duration')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index('appointment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_services');
    }
};
```

- [ ] **Step 4: Write the model**

`backend/app/Models/AppointmentService.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * No tenant scope of its own: a line is only ever reached through its
 * appointment, which is scoped — the same arrangement PayrollLine uses.
 */
class AppointmentService extends Model
{
    use HasFactory;

    protected $fillable = [
        'appointment_id',
        'service_id',
        'name',
        'price',
        'duration',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'duration' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
```

- [ ] **Step 5: Add the relations to `Appointment`**

In `backend/app/Models/Appointment.php`, add the imports `use Illuminate\Database\Eloquent\Relations\BelongsToMany;` and, after the existing `service()` relation (which stays for now — Task 8 removes it):

```php
    /** The services on this visit, in the order the customer picked them. */
    public function lines(): HasMany
    {
        return $this->hasMany(AppointmentService::class)->orderBy('sort_order');
    }

    /** The menu services behind those lines, for reporting reads. */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'appointment_services')
            ->withPivot(['name', 'price', 'duration', 'sort_order']);
    }
```

- [ ] **Step 6: Write the factory**

`backend/database/factories/AppointmentServiceFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\AppointmentService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppointmentService>
 */
class AppointmentServiceFactory extends Factory
{
    protected $model = AppointmentService::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Haircut', 'Blow Dry', 'Colour', 'Shave']),
            'price' => fake()->randomElement([15, 20, 40, 60]),
            'duration' => fake()->randomElement([15, 30, 45, 60]),
            'sort_order' => 0,
        ];
    }
}
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `cd backend && php artisan test tests/Feature/Booking/AppointmentServiceLineTest.php`
Expected: PASS, 3 tests.

- [ ] **Step 8: Commit**

```bash
git add backend/database/migrations/2026_08_09_100000_create_appointment_services_table.php \
        backend/app/Models/AppointmentService.php \
        backend/app/Models/Appointment.php \
        backend/database/factories/AppointmentServiceFactory.php \
        backend/tests/Feature/Booking/AppointmentServiceLineTest.php
git commit -m "feat: add appointment service line items"
```

---

### Task 2: `AppointmentServiceWriter` — the one place totals are computed

**Files:**
- Create: `backend/app/Actions/AppointmentServiceWriter.php`
- Test: `backend/tests/Feature/Booking/AppointmentServiceWriterTest.php`

**Interfaces:**
- Consumes: `Appointment::lines()`, `AppointmentService` (Task 1).
- Produces:
  - `AppointmentServiceWriter::sync(Appointment $appointment, array $serviceIds): void` — replaces the lines, then writes back `price` and `end_time`.
  - `AppointmentServiceWriter::totalsFor(array $serviceIds): array{duration: int, price: float, services: \Illuminate\Support\Collection}` — used by callers that need the duration *before* the appointment exists (conflict checks, slot lookups). Throws `ModelNotFoundException` when an id is not a service of the current tenant.

- [ ] **Step 1: Write the failing test**

`backend/tests/Feature/Booking/AppointmentServiceWriterTest.php`:

```php
<?php

namespace Tests\Feature\Booking;

use App\Actions\AppointmentServiceWriter;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AppointmentServiceWriterTest extends TestCase
{
    use RefreshDatabase;

    private Appointment $appointment;

    /** @var array<string, Service> */
    private array $services = [];

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Acme', 'slug' => 'acme',
            'email' => 'owner@acme.test', 'subscription_plan' => 'free', 'status' => 'active',
        ]);
        app(CurrentTenant::class)->set($org);

        $staff = User::create([
            'organization_id' => $org->id, 'name' => 'Stylist', 'email' => 'stylist@acme.test',
            'password' => 'secret1234', 'role' => 'staff', 'status' => 'active',
        ]);
        $branch = Branch::create(['organization_id' => $org->id, 'name' => 'Main']);
        $customer = Customer::create([
            'organization_id' => $org->id, 'name' => 'Casey', 'phone' => '+15550100',
        ]);

        $this->services['cut'] = Service::create([
            'organization_id' => $org->id, 'name' => 'Haircut',
            'duration' => 30, 'price' => 40, 'status' => 'active',
        ]);
        $this->services['dry'] = Service::create([
            'organization_id' => $org->id, 'name' => 'Blow Dry',
            'duration' => 20, 'price' => 15, 'status' => 'active',
        ]);

        $this->appointment = Appointment::create([
            'organization_id' => $org->id, 'branch_id' => $branch->id,
            'customer_id' => $customer->id, 'staff_id' => $staff->id,
            // Still required until Task 8 drops it; the writer ignores it.
            'service_id' => $this->services['cut']->id,
            'booking_date' => '2026-09-01', 'start_time' => '10:00:00',
            'end_time' => '10:00:00', 'price' => 0, 'status' => 'pending',
        ]);
    }

    public function test_sync_writes_lines_and_recomputes_the_total_and_end_time(): void
    {
        app(AppointmentServiceWriter::class)->sync($this->appointment, [
            $this->services['cut']->id,
            $this->services['dry']->id,
        ]);

        $fresh = $this->appointment->fresh()->load('lines');

        $this->assertSame(['Haircut', 'Blow Dry'], $fresh->lines->pluck('name')->all());
        $this->assertSame([0, 1], $fresh->lines->pluck('sort_order')->all());
        $this->assertSame('55.00', $fresh->price);
        // 10:00 + 30 + 20
        $this->assertSame('10:50:00', $fresh->end_time);
    }

    public function test_sync_replaces_the_previous_lines(): void
    {
        $writer = app(AppointmentServiceWriter::class);

        $writer->sync($this->appointment, [$this->services['cut']->id, $this->services['dry']->id]);
        $writer->sync($this->appointment, [$this->services['dry']->id]);

        $fresh = $this->appointment->fresh()->load('lines');

        $this->assertCount(1, $fresh->lines);
        $this->assertSame('15.00', $fresh->price);
        $this->assertSame('10:20:00', $fresh->end_time);
    }

    public function test_totals_for_sums_duration_and_price(): void
    {
        $totals = app(AppointmentServiceWriter::class)->totalsFor([
            $this->services['cut']->id,
            $this->services['dry']->id,
        ]);

        $this->assertSame(50, $totals['duration']);
        $this->assertSame(55.0, $totals['price']);
    }

    public function test_totals_for_rejects_a_service_outside_the_tenant(): void
    {
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        app(AppointmentServiceWriter::class)->totalsFor([$this->services['cut']->id, 99999]);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd backend && php artisan test tests/Feature/Booking/AppointmentServiceWriterTest.php`
Expected: FAIL — `Class "App\Actions\AppointmentServiceWriter" not found`.

- [ ] **Step 3: Write the action**

`backend/app/Actions/AppointmentServiceWriter.php`:

```php
<?php

namespace App\Actions;

use App\Models\Appointment;
use App\Models\Service;
use App\Services\AppointmentScheduler;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * The single definition of what a visit costs and how long it takes.
 *
 * Public booking, dashboard create, and dashboard edit all write lines
 * through here, so the three paths cannot drift on how a total is computed.
 */
class AppointmentServiceWriter
{
    public function __construct(protected AppointmentScheduler $scheduler) {}

    /**
     * Replace the appointment's lines with fresh snapshots of the given
     * services, then write back the derived total and end time.
     *
     * @param  list<int>  $serviceIds  in the order the customer picked them
     */
    public function sync(Appointment $appointment, array $serviceIds): void
    {
        $totals = $this->totalsFor($serviceIds);

        $appointment->lines()->delete();

        foreach ($totals['services']->values() as $index => $service) {
            $appointment->lines()->create([
                'service_id' => $service->id,
                'name' => $service->name,
                'price' => $service->price,
                'duration' => $service->duration,
                'sort_order' => $index,
            ]);
        }

        $appointment->forceFill([
            'price' => $totals['price'],
            'end_time' => $this->scheduler->deriveEndTime(
                $appointment->start_time,
                $totals['duration'],
            ),
        ])->save();

        $appointment->unsetRelation('lines');
    }

    /**
     * Duration, price, and the services themselves for a set of ids, in the
     * order given. Callers that need the duration before an appointment row
     * exists (conflict checks, slot lookups) use this.
     *
     * Service is tenant-scoped, so an id belonging to another salon simply
     * does not resolve — hence the explicit miss check rather than a filter.
     *
     * @param  list<int>  $serviceIds
     * @return array{duration: int, price: float, services: Collection<int, Service>}
     */
    public function totalsFor(array $serviceIds): array
    {
        $found = Service::query()->findMany($serviceIds)->keyBy('id');

        $services = collect($serviceIds)->map(function ($id) use ($found): Service {
            $service = $found->get((int) $id);

            if ($service === null) {
                throw (new ModelNotFoundException)->setModel(Service::class, [$id]);
            }

            return $service;
        });

        return [
            'duration' => (int) $services->sum('duration'),
            'price' => round((float) $services->sum(fn (Service $s) => (float) $s->price), 2),
            'services' => $services,
        ];
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd backend && php artisan test tests/Feature/Booking/AppointmentServiceWriterTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Actions/AppointmentServiceWriter.php \
        backend/tests/Feature/Booking/AppointmentServiceWriterTest.php
git commit -m "feat: add AppointmentServiceWriter for line totals"
```

---

### Task 3: Backfill existing appointments into lines

**Files:**
- Create: `backend/database/migrations/2026_08_09_100100_backfill_appointment_services.php`
- Test: `backend/tests/Feature/Migrations/AppointmentServiceBackfillTest.php`

**Interfaces:**
- Consumes: `appointment_services` (Task 1). `appointments.service_id` still exists at this point — Task 8 drops it.
- Produces: every pre-existing appointment has exactly one line.

- [ ] **Step 1: Write the failing test**

Read `backend/tests/Feature/Migrations/ApexDomainBackfillTest.php` first — it is the existing pattern for testing a data migration by re-running its `up()`.

`backend/tests/Feature/Migrations/AppointmentServiceBackfillTest.php`:

```php
<?php

namespace Tests\Feature\Migrations;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AppointmentServiceBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_turns_each_legacy_service_id_into_exactly_one_line(): void
    {
        $org = Organization::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Acme', 'slug' => 'acme',
            'email' => 'owner@acme.test', 'subscription_plan' => 'free', 'status' => 'active',
        ]);
        $staff = User::create([
            'organization_id' => $org->id, 'name' => 'Stylist', 'email' => 'stylist@acme.test',
            'password' => 'secret1234', 'role' => 'staff', 'status' => 'active',
        ]);
        $branch = Branch::create(['organization_id' => $org->id, 'name' => 'Main']);
        $customer = Customer::create([
            'organization_id' => $org->id, 'name' => 'Casey', 'phone' => '+15550100',
        ]);
        $service = Service::create([
            'organization_id' => $org->id, 'name' => 'Haircut',
            'duration' => 30, 'price' => 40, 'status' => 'active',
        ]);

        // Written straight to the table: by the time this test runs the model
        // may already have dropped service_id from $fillable.
        $appointmentId = DB::table('appointments')->insertGetId([
            'organization_id' => $org->id, 'branch_id' => $branch->id,
            'customer_id' => $customer->id, 'staff_id' => $staff->id,
            'service_id' => $service->id, 'public_token' => (string) Str::uuid(),
            'booking_date' => '2026-01-05', 'start_time' => '10:00:00', 'end_time' => '10:30:00',
            // Deliberately not 40: the booking was quoted at a price the menu
            // has since left behind, and the backfill must preserve it.
            'price' => 35, 'status' => 'completed',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('appointment_services')->delete();

        $this->runBackfill();

        $lines = DB::table('appointment_services')->where('appointment_id', $appointmentId)->get();

        $this->assertCount(1, $lines);
        $this->assertSame($service->id, (int) $lines[0]->service_id);
        $this->assertSame('Haircut', $lines[0]->name);
        // Compared as a number, not a string: these are raw query results, so
        // the model's decimal:2 cast never runs and each database engine picks
        // its own trailing zeros.
        $this->assertSame(35.0, (float) $lines[0]->price);
        $this->assertSame(30, (int) $lines[0]->duration);
        $this->assertSame(0, (int) $lines[0]->sort_order);

        // The appointment's own total is untouched.
        $this->assertSame(35.0, (float) DB::table('appointments')->find($appointmentId)->price);
    }

    public function test_it_is_safe_to_run_twice(): void
    {
        $this->test_it_turns_each_legacy_service_id_into_exactly_one_line();

        $this->runBackfill();

        $this->assertSame(1, DB::table('appointment_services')->count());
    }

    private function runBackfill(): void
    {
        $migration = require database_path('migrations/2026_08_09_100100_backfill_appointment_services.php');
        $migration->up();
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd backend && php artisan test tests/Feature/Migrations/AppointmentServiceBackfillTest.php`
Expected: FAIL — the migration file does not exist.

- [ ] **Step 3: Write the migration**

`backend/database/migrations/2026_08_09_100100_backfill_appointment_services.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Every existing booking becomes a one-line booking.
     *
     * The line's price is the appointment's own snapshot price, not the
     * service's current price: that snapshot is what the customer was quoted,
     * and it is what the invoice and the revenue reports have always shown.
     * Duration comes from the service because the appointment never stored
     * one; where the service is already gone, it is derived from the booked
     * window instead.
     *
     * Skips appointments that already have a line, so a re-run is a no-op.
     */
    public function up(): void
    {
        DB::table('appointments')
            ->leftJoin('services', 'services.id', '=', 'appointments.service_id')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('appointment_services')
                    ->whereColumn('appointment_services.appointment_id', 'appointments.id');
            })
            ->select([
                'appointments.id',
                'appointments.service_id',
                'appointments.price',
                'appointments.start_time',
                'appointments.end_time',
                'services.name',
                'services.duration',
            ])
            // chunkById, not chunk: inserting a line makes its appointment stop
            // matching whereNotExists, so an offset-paged chunk() would step
            // straight over every row the previous page just fixed.
            ->chunkById(500, function ($rows): void {
                $now = now();
                $lines = [];

                foreach ($rows as $row) {
                    $lines[] = [
                        'appointment_id' => $row->id,
                        'service_id' => $row->service_id,
                        'name' => $row->name ?? 'Service',
                        'price' => $row->price,
                        'duration' => $row->duration ?? $this->minutesBetween($row->start_time, $row->end_time),
                        'sort_order' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($lines !== []) {
                    DB::table('appointment_services')->insert($lines);
                }
            }, 'appointments.id', 'id');
    }

    /**
     * The booking has no service left to ask, so its own window is the only
     * remaining record of how long it took.
     */
    private function minutesBetween(string $start, string $end): int
    {
        return (int) max(0, (strtotime($end) - strtotime($start)) / 60);
    }

    public function down(): void
    {
        // The lines are wholly derived from appointments.service_id, which
        // still exists at this point; clearing them is a clean reversal.
        DB::table('appointment_services')->delete();
    }
};
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd backend && php artisan test tests/Feature/Migrations/AppointmentServiceBackfillTest.php`
Expected: PASS, 2 tests.

- [ ] **Step 5: Commit**

```bash
git add backend/database/migrations/2026_08_09_100100_backfill_appointment_services.php \
        backend/tests/Feature/Migrations/AppointmentServiceBackfillTest.php
git commit -m "feat: backfill existing appointments into service lines"
```

---

### Task 4: `SlotGenerator` takes a duration instead of a `Service`

**Files:**
- Modify: `backend/app/Services/SlotGenerator.php:41` (signature) and `:107-109` (`$service->duration` uses)
- Modify: `backend/app/Http/Controllers/Public/BookingController.php` (the `slots()` and `book()` call sites)
- Modify: `backend/app/Http/Controllers/Customer/BookingController.php:78`, `:99`, `:101`
- Test: `backend/tests/Feature/Booking/BlockedBookingTest.php` (existing call sites), `backend/tests/Feature/Booking/StaffTimeOffSlotTest.php`, `backend/tests/Feature/Booking/BranchClosureSlotTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `SlotGenerator::generate(int $durationMinutes, User $staff, string $date, ?Branch $branch = null, ?int $excludeAppointmentId = null): array`.

This task is a pure refactor with no behavior change — the existing slot tests are the safety net.

- [ ] **Step 1: Change the signature**

In `backend/app/Services/SlotGenerator.php`, change the docblock's first `@param` line and the signature:

```php
    /**
     * Open 'H:i' start times for the staff member on the date.
     *
     * $durationMinutes is the whole visit: the sum of every service booked,
     * performed back-to-back by this one staff member.
     *
     * ... (rest of the existing docblock unchanged)
     */
    public function generate(int $durationMinutes, User $staff, string $date, ?Branch $branch = null, ?int $excludeAppointmentId = null): array
```

Then replace both `$service->duration` uses in the candidate loop with `$durationMinutes`, and drop the now-unused `use App\Models\Service;` import.

- [ ] **Step 2: Update the three call sites**

`Public/BookingController::slots()` — replace `$slotGenerator->generate($service, $staff, ...)` with `$slotGenerator->generate($service->duration, $staff, ...)` for now (Task 6 changes it to the line sum).

`Public/BookingController::book()` — same substitution in the availability re-check.

`Customer/BookingController` at `:78`, `:99`, `:101` — the booking's duration is no longer on a single service. Use the stored window, which is authoritative for an existing booking:

```php
        $booking->loadMissing(['staff', 'branch']);

        // An existing booking already knows how long it takes: its own window.
        $duration = (int) ((strtotime($booking->end_time) - strtotime($booking->start_time)) / 60);
```

and pass `$duration` to `generate()` and to `deriveEndTime()`. Drop `'service'` from the `loadMissing` calls at `:74` and `:96` (Task 7 handles the remaining `service` reads in this controller).

- [ ] **Step 3: Run the slot tests**

Run: `cd backend && php artisan test tests/Feature/Booking tests/Feature/Public`
Expected: PASS — no behavior change.

- [ ] **Step 4: Commit**

```bash
git add backend/app/Services/SlotGenerator.php \
        backend/app/Http/Controllers/Public/BookingController.php \
        backend/app/Http/Controllers/Customer/BookingController.php
git commit -m "refactor: slot generation takes a duration, not a service"
```

---

### Task 5: Dashboard appointments accept `service_ids[]`

**Files:**
- Create: `backend/database/migrations/2026_08_09_100150_make_appointments_service_id_nullable.php`
- Modify: `backend/app/Http/Requests/Appointment/StoreAppointmentRequest.php:31`
- Modify: `backend/app/Http/Requests/Appointment/UpdateAppointmentRequest.php:48`
- Modify: `backend/app/Http/Controllers/AppointmentController.php:60-130`
- Modify: `backend/app/Http/Resources/AppointmentResource.php:35-40`
- Test: `backend/tests/Feature/Booking/MultiServiceBookingTest.php` (create), `backend/tests/Feature/Crud/AppointmentCrudTest.php` (update existing)

**Interfaces:**
- Consumes: `AppointmentServiceWriter::sync()` / `totalsFor()` (Task 2).
- Produces: `AppointmentResource` emits `services: [{id, name, price, duration}]` and `duration` (total minutes); `service` is gone.

- [ ] **Step 1: Write the failing test**

`backend/tests/Feature/Booking/MultiServiceBookingTest.php`:

```php
<?php

namespace Tests\Feature\Booking;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MultiServiceBookingTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $env;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Acme', 'slug' => 'acme',
            'email' => 'owner@acme.test', 'subscription_plan' => 'free', 'status' => 'active',
        ]);
        $owner = User::create([
            'organization_id' => $org->id, 'name' => 'Owner', 'email' => 'owner@acme.test',
            'password' => 'secret1234', 'role' => 'owner', 'status' => 'active',
        ]);
        $staff = User::create([
            'organization_id' => $org->id, 'name' => 'Stylist', 'email' => 'stylist@acme.test',
            'password' => 'secret1234', 'role' => 'staff', 'status' => 'active',
        ]);

        $this->env = [
            'org' => $org,
            'token' => $owner->createToken('api')->plainTextToken,
            'staff' => $staff,
            'branch' => Branch::create(['organization_id' => $org->id, 'name' => 'Main']),
            'customer' => Customer::create([
                'organization_id' => $org->id, 'name' => 'Casey', 'phone' => '+15550100',
            ]),
            'cut' => Service::create([
                'organization_id' => $org->id, 'name' => 'Haircut',
                'duration' => 30, 'price' => 40, 'status' => 'active',
            ]),
            'dry' => Service::create([
                'organization_id' => $org->id, 'name' => 'Blow Dry',
                'duration' => 20, 'price' => 15, 'status' => 'active',
            ]),
        ];
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'branch_id' => $this->env['branch']->id,
            'customer_id' => $this->env['customer']->id,
            'staff_id' => $this->env['staff']->id,
            'service_ids' => [$this->env['cut']->id, $this->env['dry']->id],
            'booking_date' => '2026-09-01',
            'start_time' => '10:00',
        ], $overrides);
    }

    public function test_a_two_service_booking_sums_price_and_duration(): void
    {
        $response = $this->withToken($this->env['token'])
            ->postJson('/api/appointments', $this->payload())
            ->assertCreated();

        $response->assertJsonPath('data.price', '55.00');
        $response->assertJsonPath('data.end_time', '10:50');
        $response->assertJsonPath('data.duration', 50);
        $response->assertJsonPath('data.services.0.name', 'Haircut');
        $response->assertJsonPath('data.services.1.name', 'Blow Dry');
    }

    public function test_conflict_detection_covers_the_whole_summed_block(): void
    {
        $this->withToken($this->env['token'])
            ->postJson('/api/appointments', $this->payload())
            ->assertCreated();

        // 10:40 falls inside the 10:00–10:50 block only because of the second
        // service; a single-service booking would have ended at 10:30.
        $this->withToken($this->env['token'])
            ->postJson('/api/appointments', $this->payload([
                'start_time' => '10:40',
                'service_ids' => [$this->env['cut']->id],
            ]))
            // 422, not 409: this is the existing shared conflictResponse(),
            // which every other dashboard conflict test already asserts.
            // Changing that status is not this branch's job.
            ->assertStatus(422);
    }

    public function test_editing_the_service_set_recomputes_the_total(): void
    {
        $id = $this->withToken($this->env['token'])
            ->postJson('/api/appointments', $this->payload())
            ->json('data.id');

        $this->withToken($this->env['token'])
            ->patchJson("/api/appointments/{$id}", ['service_ids' => [$this->env['dry']->id]])
            ->assertOk()
            ->assertJsonPath('data.price', '15.00')
            ->assertJsonPath('data.end_time', '10:20')
            ->assertJsonCount(1, 'data.services');

        $this->assertDatabaseCount('appointment_services', 1);
    }

    public function test_an_empty_service_list_is_rejected(): void
    {
        $this->withToken($this->env['token'])
            ->postJson('/api/appointments', $this->payload(['service_ids' => []]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('service_ids');
    }

    public function test_a_service_from_another_salon_is_rejected(): void
    {
        $other = Organization::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Rival', 'slug' => 'rival',
            'email' => 'owner@rival.test', 'subscription_plan' => 'free', 'status' => 'active',
        ]);
        $foreign = Service::create([
            'organization_id' => $other->id, 'name' => 'Foreign',
            'duration' => 30, 'price' => 99, 'status' => 'active',
        ]);

        $this->withToken($this->env['token'])
            ->postJson('/api/appointments', $this->payload([
                'service_ids' => [$this->env['cut']->id, $foreign->id],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('service_ids.1');

        $this->assertSame(0, Appointment::query()->count());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd backend && php artisan test tests/Feature/Booking/MultiServiceBookingTest.php`
Expected: FAIL — `service_ids` is not a known field; the first assertion fails on `service_id` being required.

- [ ] **Step 3: Let `appointments.service_id` go null**

This task is where the appointment writers stop populating the legacy column, but Task 8 is where it is dropped — in between, a NOT NULL column nobody writes would fail every insert.

`backend/database/migrations/2026_08_09_100150_make_appointments_service_id_nullable.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Appointments are written from their line items now, so nothing sets
     * this column any more. It survives, unwritten, only until the readers
     * have all moved across and 2026_08_09_100200 drops it.
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('service_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('service_id')->nullable(false)->change();
        });
    }
};
```

- [ ] **Step 4: Update the two form requests**

In `StoreAppointmentRequest` replace the `service_id` rule with:

```php
            // The services on this visit, in the order the customer picked
            // them. Each must belong to this salon; a bare id is never trusted.
            'service_ids' => ['required', 'array', 'min:1'],
            'service_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('services', 'id')->where('organization_id', $tenantId),
            ],
```

In `UpdateAppointmentRequest` use the same rules but with `'sometimes'` in place of `'required'` on `service_ids`, matching how the other fields there are optional.

Keep whatever `$tenantId` expression each file already uses for its other rules.

- [ ] **Step 5: Update `AppointmentController`**

Inject the writer and use it for both create and update:

```php
    public function __construct(
        protected AppointmentScheduler $scheduler,
        protected AppointmentServiceWriter $writer,
    ) {}
```

`store()` — replace the single-service block:

```php
        $data = $request->validated();

        // The whole visit's duration drives the block this booking occupies.
        $totals = $this->writer->totalsFor($data['service_ids']);
        $startTime = $this->scheduler->normalizeTime($data['start_time']);
        $endTime = $this->scheduler->deriveEndTime($data['start_time'], $totals['duration']);

        if ($this->scheduler->hasConflict($data['staff_id'], $data['booking_date'], $startTime, $endTime)) {
            return $this->conflictResponse();
        }

        $appointment = DB::transaction(function () use ($data, $startTime, $endTime) {
            $appointment = Appointment::create([
                'branch_id' => $data['branch_id'],
                'customer_id' => $this->resolveCustomerId($data),
                'staff_id' => $data['staff_id'],
                'booking_date' => $data['booking_date'],
                'start_time' => $startTime,
                'end_time' => $endTime,
                'price' => 0,
                'status' => $data['status'] ?? AppointmentStatus::PENDING->value,
                'notes' => $data['notes'] ?? null,
            ]);

            // Freezes each line at today's menu price and writes back the
            // appointment's own total and end time.
            $this->writer->sync($appointment, $data['service_ids']);

            return $appointment->fresh();
        });
```

`update()` — replace the duration lookup and add the sync:

```php
        $serviceIds = $data['service_ids'] ?? $appointment->lines->pluck('service_id')->filter()->all();
        $duration = $this->writer->totalsFor($serviceIds)['duration'];

        // ... existing start/end derivation and conflict check, using $duration

        unset($data['service_ids']);

        $appointment = DB::transaction(function () use ($appointment, $data, $serviceIds) {
            $appointment->update($data);
            $this->writer->sync($appointment, $serviceIds);

            return $appointment;
        });
```

Change `private const RELATIONS` from `['customer', 'staff', 'service', 'branch']` to `['customer', 'staff', 'lines', 'branch']`.

- [ ] **Step 6: Update `AppointmentResource`**

Replace the `service` key with:

```php
            'services' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line) => [
                'id' => $line->service_id,
                'name' => $line->name,
                'price' => $line->price,
                'duration' => $line->duration,
            ])->values(), []),
            // The whole visit, so the calendar can size a block without
            // re-adding the lines on the client.
            'duration' => $this->whenLoaded('lines', fn () => (int) $this->lines->sum('duration'), 0),
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `cd backend && php artisan test tests/Feature/Booking/MultiServiceBookingTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 8: Update the existing dashboard tests**

Run: `cd backend && php artisan test tests/Feature/Crud/AppointmentCrudTest.php tests/Feature/Crud/AppointmentRangeTest.php tests/Feature/Dashboard tests/Feature/Authorization`

Every failure is a test still posting `service_id` or asserting `data.service`. Change `'service_id' => $service->id` to `'service_ids' => [$service->id]` and `data.service.name` to `data.services.0.name`. Do not weaken any assertion.

- [ ] **Step 9: Commit**

```bash
git add backend/database/migrations/2026_08_09_100150_make_appointments_service_id_nullable.php \
        backend/app/Http/Requests/Appointment backend/app/Http/Controllers/AppointmentController.php \
        backend/app/Http/Resources/AppointmentResource.php \
        backend/tests/Feature/Booking/MultiServiceBookingTest.php backend/tests/Feature/Crud backend/tests/Feature/Dashboard
git commit -m "feat: dashboard appointments accept multiple services"
```

---

### Task 6: Public booking accepts `service_ids[]`, staff filtered by intersection

**Files:**
- Modify: `backend/app/Http/Requests/Public/PublicBookingRequest.php:32`
- Modify: `backend/app/Http/Controllers/Public/BookingController.php` — `staffForService`, `staffForServiceOnHost`, `staffPayload`, `slots`, `book`, `bookingPayload`
- Modify: `backend/routes/api.php:175`, `:221`
- Test: `backend/tests/Feature/Public/PublicBookingTest.php`, `backend/tests/Feature/Public/PublicBookingDepositTest.php` (update), plus new cases below

**Interfaces:**
- Consumes: `AppointmentServiceWriter` (Task 2), `SlotGenerator::generate(int $duration, ...)` (Task 4).
- Produces: `GET /staff?service_ids[]=`, `GET /slots?service_ids[]=`, `POST /book` with `service_ids[]`.

- [ ] **Step 1: Write the failing tests**

Append to `backend/tests/Feature/Public/PublicBookingTest.php` (reuse whatever scaffolding helper that file already has; the assertions are what matter):

```php
    public function test_the_staff_list_only_offers_people_who_can_do_every_service(): void
    {
        // Alex does both; Sam only cuts.
        $alex = $this->makeStaff('Alex');
        $sam = $this->makeStaff('Sam');
        $cut = $this->makeService('Haircut', 30, 40);
        $colour = $this->makeService('Colour', 60, 90);

        $alex->services()->sync([$cut->id, $colour->id]);
        $sam->services()->sync([$cut->id]);

        $names = $this->getJson("/api/public/{$this->org->slug}/staff?service_ids[]={$cut->id}&service_ids[]={$colour->id}")
            ->assertOk()
            ->json('data.*.name');

        $this->assertSame(['Alex'], $names);
    }

    public function test_an_unconfigured_salon_still_offers_every_active_staff_member(): void
    {
        // No service anywhere has a staff assignment, so the salon must stay
        // bookable rather than showing an empty stylist step.
        $this->makeStaff('Alex');
        $this->makeStaff('Sam');
        $cut = $this->makeService('Haircut', 30, 40);
        $colour = $this->makeService('Colour', 60, 90);

        $names = $this->getJson("/api/public/{$this->org->slug}/staff?service_ids[]={$cut->id}&service_ids[]={$colour->id}")
            ->assertOk()
            ->json('data.*.name');

        $this->assertSame(['Alex', 'Sam'], $names);
    }

    public function test_slots_are_sized_by_the_summed_duration(): void
    {
        $staff = $this->makeStaff('Alex');
        $cut = $this->makeService('Haircut', 30, 40);
        $colour = $this->makeService('Colour', 60, 90);
        $staff->services()->sync([$cut->id, $colour->id]);

        $date = now()->addWeek()->format('Y-m-d');

        $single = $this->getJson("/api/public/{$this->org->slug}/slots?service_ids[]={$cut->id}&staff_id={$staff->id}&date={$date}")
            ->assertOk()->json('data.slots');
        $both = $this->getJson("/api/public/{$this->org->slug}/slots?service_ids[]={$cut->id}&service_ids[]={$colour->id}&staff_id={$staff->id}&date={$date}")
            ->assertOk()->json('data.slots');

        // A 90-minute visit cannot start as late in the day as a 30-minute one.
        $this->assertLessThan(count($single), count($both));
    }

    public function test_a_public_multi_service_booking_stores_every_line(): void
    {
        $staff = $this->makeStaff('Alex');
        $cut = $this->makeService('Haircut', 30, 40);
        $dry = $this->makeService('Blow Dry', 20, 15);
        $staff->services()->sync([$cut->id, $dry->id]);

        $date = now()->addWeek()->format('Y-m-d');
        $slot = $this->getJson("/api/public/{$this->org->slug}/slots?service_ids[]={$cut->id}&service_ids[]={$dry->id}&staff_id={$staff->id}&date={$date}")
            ->json('data.slots.0');

        $response = $this->postJson("/api/public/{$this->org->slug}/book", [
            'service_ids' => [$cut->id, $dry->id],
            'staff_id' => $staff->id,
            'date' => $date,
            'start_time' => $slot,
            'customer' => ['name' => 'Casey', 'phone' => '+15550100'],
        ])->assertCreated();

        $response->assertJsonPath('data.price', '55.00');
        $this->assertSame(['Haircut', 'Blow Dry'], $response->json('data.services.*.name'));
        $this->assertDatabaseCount('appointment_services', 2);
    }
```

If `PublicBookingTest` has no `makeStaff`/`makeService` helpers, add them alongside its existing scaffolding rather than inlining the setup three times.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd backend && php artisan test tests/Feature/Public/PublicBookingTest.php`
Expected: FAIL — the `/staff` route does not exist; `service_ids` is not accepted.

- [ ] **Step 3: Replace the staff endpoint**

In `backend/routes/api.php`, replace both `Route::get('services/{service}/staff', ...)` lines (`:175` slug group, `:221` host group) with:

```php
    // Staff who can perform every selected service. A visit is done by one
    // person back-to-back, so the list is an intersection, not a union.
    Route::get('staff', [BookingController::class, 'staffForServices']);
```

In `Public/BookingController`, delete `staffForService` and `staffForServiceOnHost` and replace `staffPayload` with:

```php
    /**
     * Staff who can perform *every* requested service. When no service in the
     * salon has any staff assignment (assignment is optional), fall back to
     * every active staff member so the salon is still bookable.
     */
    public function staffForServices(Request $request): JsonResponse
    {
        $tenantId = app(CurrentTenant::class)->id();

        $validated = $request->validate([
            'service_ids' => ['required', 'array', 'min:1'],
            'service_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('services', 'id')->where('organization_id', $tenantId),
            ],
        ]);

        $serviceIds = $validated['service_ids'];

        if (! Service::has('staff')->exists()) {
            $staff = User::where('organization_id', $tenantId)
                ->where('role', UserRole::STAFF->value)
                ->where('status', 'active')
                ->with('staffProfile')
                ->get();
        } else {
            // One row per (staff, service) match; a staff member qualifies
            // only when they match all of them.
            $staff = User::where('organization_id', $tenantId)
                ->where('role', UserRole::STAFF->value)
                ->where('status', 'active')
                ->whereHas('services', fn ($q) => $q->whereIn('services.id', $serviceIds), '=', count($serviceIds))
                ->with('staffProfile')
                ->get();
        }

        return response()->json([
            'data' => $staff->map(fn (User $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'designation' => $member->staffProfile?->designation,
                'profile_image' => $member->staffProfile?->profile_image,
            ])->values(),
        ]);
    }
```

- [ ] **Step 4: Update `slots()` and `book()`**

In `slots()`, swap the `service_id` rule for the `service_ids` pair used above, then:

```php
        $totals = $this->writer->totalsFor($validated['service_ids']);
        // ... staff and branch resolution unchanged
                'slots' => $slotGenerator->generate($totals['duration'], $staff, $validated['date'], $branch),
```

In `book()`, replace `$service = Service::findOrFail($data['service_id']);` with `$totals = $this->writer->totalsFor($data['service_ids']);`, derive `$endTime` from `$totals['duration']`, pass `$totals['duration']` to the availability re-check, compute the deposit from `$totals['price']`, and inside the transaction create the appointment with `'price' => 0` then call `$this->writer->sync($appointment, $data['service_ids'])` before creating any payment row (the deposit is a fraction of the total, which must already be written).

Change the eager load after the transaction from `'service'` to `'lines'`, and update `bookingPayload()` to emit `services` (name/price/duration per line) instead of a single `service`.

Inject the writer into the constructor alongside the existing `AppointmentScheduler`.

- [ ] **Step 5: Update `PublicBookingRequest`**

Replace the `service_id` rule with the same `service_ids` / `service_ids.*` pair. Leave every other rule untouched.

- [ ] **Step 6: Run the public tests**

Run: `cd backend && php artisan test tests/Feature/Public`
Expected: PASS. Update any existing test still sending `service_id` or reading `data.service`.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Http/Controllers/Public/BookingController.php \
        backend/app/Http/Requests/Public/PublicBookingRequest.php \
        backend/routes/api.php backend/tests/Feature/Public
git commit -m "feat: public booking accepts multiple services"
```

---

### Task 7: Customer portal, emails, and review admin read lines

**Files:**
- Modify: `backend/app/Http/Controllers/Customer/BookingController.php:31`, `:58`, `:107`, `:176`
- Modify: `backend/app/Services/BookingNotifier.php:62`
- Modify: `backend/resources/views/mail/booking/{customer,salon,cancelled,rescheduled}.blade.php`
- Modify: `backend/app/Http/Resources/ReviewAdminResource.php:27`
- Test: `backend/tests/Feature/Customer`, `backend/tests/Feature/Reviews`

**Interfaces:**
- Consumes: `Appointment::lines()` (Task 1).
- Produces: the customer bookings payload emits `services` (a list of names) instead of `service` (one name).

- [ ] **Step 1: Update the eager loads and the payload**

In `Customer/BookingController`, change every `'service'` in a `with()` / `load()` / `loadMissing()` list to `'lines'` (lines `:31`, `:58`, `:107`).

In `present()` at `:176`, replace:

```php
            'service' => $a->service?->name,
```

with:

```php
            'services' => $a->lines->pluck('name')->values(),
```

- [ ] **Step 2: Update the notifier and the mail views**

`BookingNotifier::deliver()` at `:62` — change `'service'` to `'lines'` in the `loadMissing` array.

In each of the four blade files, replace the single Service row:

```blade
| **Service**     | {{ $appointment->service->name }}        |
```

with:

```blade
| **Services**    | {{ $appointment->lines->pluck('name')->join(', ') }} |
```

- [ ] **Step 3: Update `ReviewAdminResource`**

Replace `'service_name' => $this->appointment?->service?->name,` with:

```php
            // A visit can carry several services; the owner reads them as one
            // line next to the review.
            'service_name' => $this->appointment?->lines->pluck('name')->join(', '),
```

Then make sure the controller that builds `ReviewAdminResource` eager-loads `appointment.lines` rather than `appointment.service` — grep for `appointment.service` under `app/Http/Controllers` and fix each hit.

- [ ] **Step 4: Run the affected tests**

Run: `cd backend && php artisan test tests/Feature/Customer tests/Feature/Reviews tests/Feature/Reminders`
Expected: PASS. Update assertions reading `service` to read `services`.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Http/Controllers/Customer/BookingController.php \
        backend/app/Services/BookingNotifier.php backend/resources/views/mail/booking \
        backend/app/Http/Resources/ReviewAdminResource.php backend/tests
git commit -m "feat: customer portal and emails list every booked service"
```

---

### Task 8: Drop `appointments.service_id`

**Files:**
- Create: `backend/database/migrations/2026_08_09_100200_drop_service_id_from_appointments_table.php`
- Modify: `backend/app/Models/Appointment.php` (remove `service_id` from `$fillable`, remove `service()`)
- Modify: `backend/app/Models/Service.php:50` (`appointments()`)
- Modify: `backend/app/Http/Controllers/ServiceController.php:93-110`
- Modify: `backend/database/factories/AppointmentFactory.php` if it references `service_id`
- Test: `backend/tests/Feature/Crud/DeleteDependencyGuardTest.php`

**Interfaces:**
- Consumes: every task above — nothing may still read `appointments.service_id`.
  **Task 12 must land before this one.** `ReportService::topServices()` selects and
  groups by `appointments.service_id`; the moment this column is dropped that query
  is a SQL error, so the reports break before Task 12 gets to fix them. Execute
  Task 12, then Task 8.
- Produces: `Service::appointments(): BelongsToMany` through `appointment_services`.

- [ ] **Step 1: Prove nothing still reads the column**

Run: `cd backend && grep -rn "service_id" app resources routes | grep -v "staff_services\|appointment_services\|service_ids"`
Expected: no hits in `app/Models/Appointment.php`, no hits in any controller. Fix anything that turns up before continuing.

- [ ] **Step 2: Update the delete guard test**

In `backend/tests/Feature/Crud/DeleteDependencyGuardTest.php`, its `makeAppointment` helper passes `'service_id' => $service->id`. Replace that with a line row so the guard still has something to find:

```php
        $appointment = Appointment::create([
            'organization_id' => $org->id, 'branch_id' => $branch->id,
            'customer_id' => $customer->id, 'staff_id' => $staff->id,
            'booking_date' => '2026-09-01', 'start_time' => '10:00:00',
            'end_time' => '10:30:00', 'price' => $service->price, 'status' => 'pending',
        ]);

        $appointment->lines()->create([
            'service_id' => $service->id, 'name' => $service->name,
            'price' => $service->price, 'duration' => $service->duration, 'sort_order' => 0,
        ]);
```

Also update the file's header comment: the cascade it describes now runs `appointment_services.appointment_id`, not `appointments.service_id`.

- [ ] **Step 3: Run the test to verify it fails**

Run: `cd backend && php artisan test tests/Feature/Crud/DeleteDependencyGuardTest.php`
Expected: FAIL on `test_deleting_a_service_with_appointments_is_refused_and_keeps_the_history` — the guard checks `Service::appointments()`, which still hangs off the dropped column.

- [ ] **Step 4: Write the migration**

`backend/database/migrations/2026_08_09_100200_drop_service_id_from_appointments_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A visit's services now live in appointment_services. The backfill in
     * 2026_08_09_100100 has already copied every value out of this column, so
     * dropping it removes the second source of truth rather than any data.
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropForeign(['service_id']);
            $table->dropColumn('service_id');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('service_id')->nullable()->after('staff_id')
                ->constrained('services')->cascadeOnDelete();
        });
    }
};
```

- [ ] **Step 5: Update the models and the guard**

`Appointment` — remove `'service_id'` from `$fillable` and delete the `service()` relation.

`Service::appointments()` — change to:

```php
    /**
     * Visits that booked this service. Reached through the line table now
     * that a visit can carry several services.
     */
    public function appointments(): BelongsToMany
    {
        return $this->belongsToMany(Appointment::class, 'appointment_services');
    }
```

Swap the `HasMany` import for `BelongsToMany` if nothing else in the file uses it.

`ServiceController::destroy` — the `$service->appointments()->exists()` check now works through the new relation and needs no code change, but its docblock's first sentence is stale. Replace it with:

```php
    /**
     * A service with visits behind it cannot be deleted. Its line items snapshot
     * the name and price, so history would in fact survive — but the owner would
     * lose the ability to report on that service by id, and the intended action
     * is almost always `status: inactive`, which hides it from the booking site
     * and keeps everything. Refuse and say so.
     */
```

- [ ] **Step 6: Run the whole backend suite**

Run: `cd backend && php artisan test`
Expected: PASS. This is the first point where the column is gone, so anything still depending on it fails loudly here.

- [ ] **Step 7: Commit**

```bash
git add backend/database/migrations/2026_08_09_100200_drop_service_id_from_appointments_table.php \
        backend/app/Models/Appointment.php backend/app/Models/Service.php \
        backend/app/Http/Controllers/ServiceController.php backend/tests
git commit -m "refactor: drop appointments.service_id in favour of line items"
```

---

### Task 9: Record a tip at checkout

**Files:**
- Create: `backend/database/migrations/2026_08_09_100300_add_tip_amount_to_payments_table.php`
- Modify: `backend/app/Models/Payment.php`
- Modify: `backend/app/Http/Requests/Payment/StorePaymentRequest.php`
- Modify: `backend/app/Http/Resources/PaymentResource.php`
- Modify: `backend/app/Models/Appointment.php` (a `tipsCollected()` helper)
- Test: `backend/tests/Feature/Crud/PaymentTipTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `payments.tip_amount`; `Appointment::tipsCollected(): string`; `PaymentResource` emits `tip_amount`.

- [ ] **Step 1: Write the failing test**

`backend/tests/Feature/Crud/PaymentTipTest.php` — reuse `PaymentControllerTest`'s `scaffold()` shape (copy it in; the two files test different things and a shared base is not worth a new class here):

```php
<?php

namespace Tests\Feature\Crud;

use App\Enums\PaymentMethod;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentTipTest extends TestCase
{
    use RefreshDatabase;

    private Appointment $appointment;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Acme', 'slug' => 'acme',
            'email' => 'owner@acme.test', 'subscription_plan' => 'free', 'status' => 'active',
        ]);
        $owner = User::create([
            'organization_id' => $org->id, 'name' => 'Owner', 'email' => 'owner@acme.test',
            'password' => 'secret1234', 'role' => 'owner', 'status' => 'active',
        ]);
        $staff = User::create([
            'organization_id' => $org->id, 'name' => 'Stylist', 'email' => 'stylist@acme.test',
            'password' => 'secret1234', 'role' => 'staff', 'status' => 'active',
        ]);
        $branch = Branch::create(['organization_id' => $org->id, 'name' => 'Main']);
        $customer = Customer::create([
            'organization_id' => $org->id, 'name' => 'Casey', 'phone' => '+15550100',
        ]);
        $service = Service::create([
            'organization_id' => $org->id, 'name' => 'Haircut',
            'duration' => 30, 'price' => 40, 'status' => 'active',
        ]);

        $this->token = $owner->createToken('api')->plainTextToken;
        $this->appointment = Appointment::create([
            'organization_id' => $org->id, 'branch_id' => $branch->id,
            'customer_id' => $customer->id, 'staff_id' => $staff->id,
            'booking_date' => '2026-09-01', 'start_time' => '10:00:00',
            'end_time' => '10:30:00', 'price' => 40, 'status' => 'completed',
        ]);
        $this->appointment->lines()->create([
            'service_id' => $service->id, 'name' => 'Haircut',
            'price' => 40, 'duration' => 30, 'sort_order' => 0,
        ]);
    }

    public function test_a_payment_can_carry_a_tip(): void
    {
        $this->withToken($this->token)
            ->postJson("/api/appointments/{$this->appointment->id}/payments", [
                'amount' => 40, 'tip_amount' => 5, 'method' => PaymentMethod::CASH->value,
            ])
            ->assertCreated()
            ->assertJsonPath('data.tip_amount', '5.00');
    }

    public function test_a_tip_does_not_reduce_the_balance(): void
    {
        $this->withToken($this->token)
            ->postJson("/api/appointments/{$this->appointment->id}/payments", [
                'amount' => 30, 'tip_amount' => 10, 'method' => PaymentMethod::CASH->value,
            ])
            ->assertCreated();

        $fresh = $this->appointment->fresh()->load('payments');

        $this->assertSame('30.00', $fresh->amountPaid());
        $this->assertSame('10.00', $fresh->balanceDue());
        $this->assertSame('10.00', $fresh->tipsCollected());
    }

    public function test_a_settled_booking_can_still_take_a_tip_only_payment(): void
    {
        $this->withToken($this->token)
            ->postJson("/api/appointments/{$this->appointment->id}/payments", [
                'amount' => 40, 'method' => PaymentMethod::CASH->value,
            ])
            ->assertCreated();

        $this->withToken($this->token)
            ->postJson("/api/appointments/{$this->appointment->id}/payments", [
                'amount' => 0, 'tip_amount' => 6, 'method' => PaymentMethod::CASH->value,
            ])
            ->assertCreated();

        $this->assertSame('6.00', $this->appointment->fresh()->load('payments')->tipsCollected());
    }

    public function test_a_payment_of_nothing_at_all_is_rejected(): void
    {
        $this->withToken($this->token)
            ->postJson("/api/appointments/{$this->appointment->id}/payments", [
                'amount' => 0, 'tip_amount' => 0, 'method' => PaymentMethod::CASH->value,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');
    }

    public function test_a_negative_tip_is_rejected(): void
    {
        $this->withToken($this->token)
            ->postJson("/api/appointments/{$this->appointment->id}/payments", [
                'amount' => 40, 'tip_amount' => -1, 'method' => PaymentMethod::CASH->value,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tip_amount');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd backend && php artisan test tests/Feature/Crud/PaymentTipTest.php`
Expected: FAIL — `tip_amount` is not a column.

- [ ] **Step 3: Write the migration**

`backend/database/migrations/2026_08_09_100300_add_tip_amount_to_payments_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A tip taken alongside this payment. Kept separate from `amount` because
     * `amount` is what settles the booking's balance and a tip settles
     * nothing — it is money for the stylist that happens to be handed over at
     * the same counter, at the same moment.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('tip_amount', 10, 2)->default(0)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('tip_amount');
        });
    }
};
```

- [ ] **Step 4: Update the model, request, and resource**

`Payment` — add `'tip_amount'` to `$fillable` and `'tip_amount' => 'decimal:2'` to `casts()`.

`StorePaymentRequest::rules()`:

```php
        return [
            // gte:0 rather than gt:0 so a customer who has already settled can
            // still leave a tip; the withValidator rule below stops a payment
            // that moves no money at all.
            'amount' => ['required', 'numeric', 'gte:0', 'max:99999999.99'],
            'tip_amount' => ['nullable', 'numeric', 'gte:0', 'max:99999999.99'],
            'method' => ['required', new Enum(PaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ((float) $this->input('amount', 0) <= 0 && (float) $this->input('tip_amount', 0) <= 0) {
                $validator->errors()->add('amount', 'Enter an amount, a tip, or both.');
            }
        });
    }
```

with `use Illuminate\Contracts\Validation\Validator;` imported.

`PaymentResource` — add `'tip_amount' => $this->tip_amount,` directly after `'amount'`.

`Appointment` — add next to `amountPaid()`:

```php
    /** Tips handed over at the counter. Never part of the booking balance. */
    public function tipsCollected(): string
    {
        $sum = $this->payments
            ->where('status', PaymentStatus::VERIFIED)
            ->sum('tip_amount');

        return number_format((float) $sum, 2, '.', '');
    }
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd backend && php artisan test tests/Feature/Crud/PaymentTipTest.php tests/Feature/Crud/PaymentControllerTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/database/migrations/2026_08_09_100300_add_tip_amount_to_payments_table.php \
        backend/app/Models/Payment.php backend/app/Models/Appointment.php \
        backend/app/Http/Requests/Payment/StorePaymentRequest.php \
        backend/app/Http/Resources/PaymentResource.php \
        backend/tests/Feature/Crud/PaymentTipTest.php
git commit -m "feat: record a tip alongside a counter payment"
```

---

### Task 10: Invoice shows every service line and the tips

**Files:**
- Modify: `backend/app/Http/Controllers/InvoiceController.php`
- Test: `backend/tests/Feature/Crud/InvoiceTest.php`

**Interfaces:**
- Consumes: `Appointment::lines()` (Task 1), `Appointment::tipsCollected()` (Task 9).
- Produces: invoice JSON with `line_items[]` per service, plus `tips` and `total_collected`.

- [ ] **Step 1: Write the failing test**

Add to `backend/tests/Feature/Crud/InvoiceTest.php` (using its existing scaffolding):

```php
    public function test_the_invoice_lists_every_service_and_separates_tips(): void
    {
        $appointment = $this->makeAppointment();   // existing helper
        $appointment->lines()->create([
            'service_id' => null, 'name' => 'Haircut',
            'price' => 40, 'duration' => 30, 'sort_order' => 0,
        ]);
        $appointment->lines()->create([
            'service_id' => null, 'name' => 'Blow Dry',
            'price' => 15, 'duration' => 20, 'sort_order' => 1,
        ]);
        $appointment->forceFill(['price' => 55])->save();

        $this->withToken($this->token)
            ->postJson("/api/appointments/{$appointment->id}/payments", [
                'amount' => 55, 'tip_amount' => 5, 'method' => 'cash',
            ])->assertCreated();

        $this->withToken($this->token)
            ->getJson("/api/appointments/{$appointment->id}/invoice")
            ->assertOk()
            ->assertJsonCount(2, 'data.line_items')
            ->assertJsonPath('data.line_items.0.description', 'Haircut')
            ->assertJsonPath('data.line_items.1.amount', '15.00')
            ->assertJsonPath('data.subtotal', '55.00')
            ->assertJsonPath('data.tips', '5.00')
            ->assertJsonPath('data.total_collected', '60.00')
            ->assertJsonPath('data.balance_due', '0.00')
            ->assertJsonPath('data.paid_in_full', true);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd backend && php artisan test tests/Feature/Crud/InvoiceTest.php`
Expected: FAIL — one line item, no `tips` key.

- [ ] **Step 3: Update the controller**

Change the eager load from `'service'` to `'lines'`, and replace the `line_items` / totals block:

```php
            'line_items' => $appointment->lines->map(fn ($line) => [
                'description' => $line->name,
                'amount' => $line->price,
            ])->values(),
            'subtotal' => $appointment->price,
            'amount_paid' => $appointment->amountPaid(),
            'amount_pending' => $appointment->amountPending(),
            // Tips sit outside the balance: they settle nothing, so they are
            // reported next to it rather than inside it.
            'tips' => $appointment->tipsCollected(),
            'total_collected' => number_format(
                (float) $appointment->amountPaid() + (float) $appointment->tipsCollected(),
                2, '.', ''
            ),
            'balance_due' => $appointment->balanceDue(),
            'paid_in_full' => (float) $appointment->balanceDue() <= 0,
```

Update the class docblock's "the service line snapshotted at booking time" to "the service lines snapshotted at booking time".

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd backend && php artisan test tests/Feature/Crud/InvoiceTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Http/Controllers/InvoiceController.php backend/tests/Feature/Crud/InvoiceTest.php
git commit -m "feat: invoice lists every service line and tips"
```

---

### Task 11: Tips flow into payroll

**Files:**
- Create: `backend/database/migrations/2026_08_09_100400_add_tips_amount_to_payroll_lines_table.php`
- Modify: `backend/app/Models/PayrollLine.php`
- Modify: `backend/app/Services/PayrollCalculator.php`
- Modify: `backend/app/Http/Resources/PayrollLineResource.php`
- Modify: `backend/app/Http/Controllers/PayrollRunController.php` (wherever a line is created from calculator output)
- Test: `backend/tests/Feature/Finance/PayrollCalculatorTest.php`

**Interfaces:**
- Consumes: `payments.tip_amount` (Task 9).
- Produces: `payroll_lines.tips_amount`; `PayrollLine::totalFor($salary, $commission, $tips): float`; calculator rows carry `tips_amount`.

- [ ] **Step 1: Write the failing test**

Add to `backend/tests/Feature/Finance/PayrollCalculatorTest.php` (using `FinanceTestCase`'s helpers):

```php
    public function test_tips_are_paid_in_full_and_left_out_of_the_commission_base(): void
    {
        $org = $this->makeOrg();
        $staff = $this->makeUser($org, 'staff');
        StaffProfile::create([
            'user_id' => $staff->id, 'pay_type' => 'commission', 'commission_rate' => 50,
        ]);

        // A completed 100.00 visit in the month, with a 20.00 tip.
        $appointment = $this->makeAppointment($org, $staff, '2026-06-10', 100, 'completed');
        $appointment->payments()->create([
            'organization_id' => $org->id,
            'amount' => 100, 'tip_amount' => 20,
            'method' => 'cash', 'status' => 'verified', 'source' => 'staff',
        ]);

        $line = collect(app(PayrollCalculator::class)->linesFor(Carbon::parse('2026-06-01')))
            ->firstWhere('staff_id', $staff->id);

        $this->assertSame(100.0, $line['earned_revenue']);   // tip excluded
        $this->assertSame(50.0, $line['commission_amount']); // 50% of 100, not of 120
        $this->assertSame(20.0, $line['tips_amount']);
        $this->assertSame(70.0, $line['total_amount']);      // commission + tips
    }

    public function test_an_unverified_tip_is_not_paid_out(): void
    {
        $org = $this->makeOrg();
        $staff = $this->makeUser($org, 'staff');
        StaffProfile::create([
            'user_id' => $staff->id, 'pay_type' => 'commission', 'commission_rate' => 50,
        ]);

        $appointment = $this->makeAppointment($org, $staff, '2026-06-10', 100, 'completed');
        $appointment->payments()->create([
            'organization_id' => $org->id,
            'amount' => 100, 'tip_amount' => 20,
            'method' => 'bank_transfer', 'status' => 'pending', 'source' => 'public_manual',
        ]);

        $line = collect(app(PayrollCalculator::class)->linesFor(Carbon::parse('2026-06-01')))
            ->firstWhere('staff_id', $staff->id);

        $this->assertSame(0.0, $line['tips_amount']);
    }
```

If `FinanceTestCase` has no `makeAppointment($org, $staff, $date, $price, $status)` helper with that signature, add one there rather than inlining it.

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd backend && php artisan test tests/Feature/Finance/PayrollCalculatorTest.php`
Expected: FAIL — `Undefined array key "tips_amount"`.

- [ ] **Step 3: Write the migration**

`backend/database/migrations/2026_08_09_100400_add_tips_amount_to_payroll_lines_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tips collected against this staff member's completed visits in the
     * month. Paid through in full and deliberately outside `earned_revenue`,
     * so no commission rate is ever applied to money a customer handed
     * directly to the stylist.
     */
    public function up(): void
    {
        Schema::table('payroll_lines', function (Blueprint $table) {
            $table->decimal('tips_amount', 10, 2)->default(0)->after('commission_amount');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_lines', function (Blueprint $table) {
            $table->dropColumn('tips_amount');
        });
    }
};
```

- [ ] **Step 4: Update `PayrollLine`**

Add `'tips_amount'` to `$fillable` and `'tips_amount' => 'decimal:2'` to `casts()`, then:

```php
    /**
     * What a line is worth, defined in exactly one place: salary plus
     * commission plus tips. PayrollCalculator builds a fresh line through
     * this, and a manual amount edit re-runs it, so the two can never disagree.
     */
    public static function totalFor(mixed $salary, mixed $commission, mixed $tips = 0): float
    {
        return round((float) $salary + (float) $commission + (float) $tips, 2);
    }

    public function recomputeTotal(): static
    {
        $this->total_amount = static::totalFor(
            $this->salary_amount,
            $this->commission_amount,
            $this->tips_amount,
        );

        return $this;
    }
```

- [ ] **Step 5: Update `PayrollCalculator`**

After the `$revenue` query, add:

```php
        // Tips ride on the same window and status filter as the revenue base,
        // so payroll and the revenue report still cannot disagree about which
        // visits counted.
        $tips = Payment::query()
            ->join('appointments', 'appointments.id', '=', 'payments.appointment_id')
            ->where('payments.status', PaymentStatus::VERIFIED->value)
            ->where('appointments.status', AppointmentStatus::COMPLETED->value)
            ->whereDate('appointments.booking_date', '>=', $start)
            ->whereDate('appointments.booking_date', '<=', $end)
            ->groupBy('appointments.staff_id')
            ->selectRaw('appointments.staff_id as staff_id, SUM(payments.tip_amount) as tips')
            ->get()
            ->keyBy('staff_id');
```

Add `$tips` to the closure's `use`, and inside it:

```php
            $tipsAmount = round((float) ($tips->get($member->id)->tips ?? 0), 2);
```

then add `'tips_amount' => $tipsAmount,` to the returned array and change the total to
`PayrollLine::totalFor($salary, $commission, $tipsAmount)`.

Import `App\Models\Payment` and `App\Enums\PaymentStatus`.

Note: `Payment` carries `BelongsToOrganization`, so the tenant filter is already applied to the payments side; the join to `appointments` inherits nothing, but every payment row is already tenant-scoped, which is sufficient.

- [ ] **Step 6: Update the resource and the run controller**

`PayrollLineResource` — add `'tips_amount' => $this->tips_amount,` after `commission_amount`.

`PayrollRunController` — wherever it creates `PayrollLine` rows from `linesFor()` output, make sure `tips_amount` is included in the attributes it passes through. Grep for `PayrollLine::create` / `->lines()->create` and add the key.

- [ ] **Step 7: Run the finance suite**

Run: `cd backend && php artisan test tests/Feature/Finance`
Expected: PASS. Any existing test asserting `total_amount` for a tip-free line is unaffected — `totalFor`'s third argument defaults to 0.

- [ ] **Step 8: Commit**

```bash
git add backend/database/migrations/2026_08_09_100400_add_tips_amount_to_payroll_lines_table.php \
        backend/app/Models/PayrollLine.php backend/app/Services/PayrollCalculator.php \
        backend/app/Http/Resources/PayrollLineResource.php \
        backend/app/Http/Controllers/PayrollRunController.php backend/tests/Feature/Finance
git commit -m "feat: pay tips through to staff in the monthly payroll run"
```

---

### Task 12: Top-services report reads line items

**Files:**
- Modify: `backend/app/Services/ReportService.php:200-232`
- Test: `backend/tests/Feature/Reports/ReportsTest.php`

**Interfaces:**
- Consumes: `appointment_services` (Task 1).
- Produces: `top_services[]` rows keyed on line data; `bookings` counts service lines.

- [ ] **Step 1: Write the failing test**

Add to `backend/tests/Feature/Reports/ReportsTest.php`:

```php
    public function test_a_multi_service_visit_is_attributed_to_every_service_it_booked(): void
    {
        $appointment = $this->makeCompletedAppointment('2026-06-10', 55); // existing helper
        $appointment->lines()->create([
            'service_id' => null, 'name' => 'Haircut',
            'price' => 40, 'duration' => 30, 'sort_order' => 0,
        ]);
        $appointment->lines()->create([
            'service_id' => null, 'name' => 'Blow Dry',
            'price' => 15, 'duration' => 20, 'sort_order' => 1,
        ]);

        $rows = $this->withToken($this->token)
            ->getJson('/api/reports?from=2026-06-01&to=2026-06-30')
            ->assertOk()
            ->json('data.top_services');

        $names = array_column($rows, 'name');

        $this->assertContains('Haircut', $names);
        $this->assertContains('Blow Dry', $names);
        $this->assertSame(40.0, collect($rows)->firstWhere('name', 'Haircut')['earned']);
        $this->assertSame(15.0, collect($rows)->firstWhere('name', 'Blow Dry')['earned']);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd backend && php artisan test tests/Feature/Reports/ReportsTest.php`
Expected: FAIL — the visit shows up once, at 55.00, under a single service.

- [ ] **Step 3: Rewrite `topServices`**

```php
    /**
     * Completed visits grouped by the services they booked, ranked by earned.
     *
     * The unit here is a service *line*, not a visit: a customer who booked a
     * cut and a colour contributes to both. `bookings` therefore counts lines,
     * which is what "how much did this service earn me" needs. Revenue totals
     * elsewhere stay on appointments.price, so the two still reconcile.
     *
     * Names come from the line snapshot, so a service since removed from the
     * menu still reports under the name it was sold as.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function topServices(string $from, string $to): array
    {
        // Keep whatever signature and window arguments the method already has;
        // only the query and the row shape below change.
        $rows = AppointmentService::query()
            ->join('appointments', 'appointments.id', '=', 'appointment_services.appointment_id')
            ->where('appointments.organization_id', app(CurrentTenant::class)->id())
            ->where('appointments.status', AppointmentStatus::COMPLETED->value)
            ->whereDate('appointments.booking_date', '>=', $from)
            ->whereDate('appointments.booking_date', '<=', $to)
            ->groupBy('appointment_services.name')
            ->selectRaw('appointment_services.name as name, MAX(appointment_services.service_id) as service_id, COUNT(*) as bookings, SUM(appointment_services.price) as earned')
            ->get();

        $total = (float) $rows->sum('earned');

        return $rows
            ->sortByDesc(fn ($row) => (float) $row->earned)
            ->take(10)
            ->map(fn ($row) => [
                'service_id' => $row->service_id !== null ? (int) $row->service_id : null,
                'name' => $row->name,
                'bookings' => (int) $row->bookings,
                'earned' => round((float) $row->earned, 2),
                'share_pct' => $total > 0 ? round((float) $row->earned / $total * 100, 1) : 0.0,
            ])
            ->values()
            ->all();
    }
```

Group by the snapshot `name`, not `service_id`: a deleted service's lines all have a null id and would otherwise collapse into one "Unknown" row. Import `App\Models\AppointmentService` and `App\Tenancy\CurrentTenant`; the explicit `organization_id` filter is needed because the query starts from `AppointmentService`, which carries no tenant scope.

Drop the now-unused `Service::query()->pluck('name', 'id')` lookup if nothing else in the file uses it.

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd backend && php artisan test tests/Feature/Reports`
Expected: PASS.

- [ ] **Step 5: Run the whole backend suite**

Run: `cd backend && php artisan test`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Services/ReportService.php backend/tests/Feature/Reports/ReportsTest.php
git commit -m "feat: attribute report revenue to every service on a visit"
```

---

### Task 13: Public booking site — multi-select services

**Files:**
- Modify: `frontend/src/views/PublicBookingView.vue`
- Test: `frontend/src/views/PublicBookingView.spec.js` (create if absent)

**Interfaces:**
- Consumes: `GET /staff?service_ids[]=`, `GET /slots?service_ids[]=`, `POST /book` with `service_ids[]` (Task 6).
- Produces: nothing later tasks depend on.

- [ ] **Step 1: Write the failing test**

`frontend/src/views/PublicBookingView.spec.js` — follow the pattern in `frontend/src/views/onboarding/StepStaff.spec.js` for mounting and mocking `api`:

```js
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import PublicBookingView from './PublicBookingView.vue'
import api from '@/lib/api'

vi.mock('@/lib/api')

describe('PublicBookingView service selection', () => {
  beforeEach(() => {
    api.get = vi.fn().mockResolvedValue({
      data: {
        data: [
          { id: 1, name: 'Haircut', duration: 30, price: '40.00' },
          { id: 2, name: 'Blow Dry', duration: 20, price: '15.00' },
        ],
      },
    })
  })

  it('sums duration and price across the selected services', async () => {
    const wrapper = mount(PublicBookingView)
    await flushPromises()

    await wrapper.vm.toggleService({ id: 1, name: 'Haircut', duration: 30, price: '40.00' })
    await wrapper.vm.toggleService({ id: 2, name: 'Blow Dry', duration: 20, price: '15.00' })

    expect(wrapper.vm.selectedServiceIds).toEqual([1, 2])
    expect(wrapper.vm.totalDuration).toBe(50)
    expect(wrapper.vm.totalPrice).toBe(55)
  })

  it('clears the chosen staff and slot when the selection changes', async () => {
    const wrapper = mount(PublicBookingView)
    await flushPromises()

    await wrapper.vm.toggleService({ id: 1, name: 'Haircut', duration: 30, price: '40.00' })
    wrapper.vm.selectedStaff = { id: 9, name: 'Alex' }
    wrapper.vm.selectedSlot = '10:00'

    await wrapper.vm.toggleService({ id: 2, name: 'Blow Dry', duration: 20, price: '15.00' })

    expect(wrapper.vm.selectedStaff).toBeNull()
    expect(wrapper.vm.selectedSlot).toBeNull()
  })
})
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd frontend && npm run test:unit -- src/views/PublicBookingView.spec.js`
Expected: FAIL — `toggleService is not a function`.

- [ ] **Step 3: Replace the single-service state**

In `PublicBookingView.vue`, replace `selectedService` with:

```js
const selectedServices = ref([])

const selectedServiceIds = computed(() => selectedServices.value.map((s) => s.id))
const totalDuration = computed(() =>
  selectedServices.value.reduce((sum, s) => sum + Number(s.duration || 0), 0),
)
const totalPrice = computed(() =>
  selectedServices.value.reduce((sum, s) => sum + Number(s.price || 0), 0),
)

function toggleService(svc) {
  const at = selectedServices.value.findIndex((s) => s.id === svc.id)
  if (at === -1) {
    selectedServices.value.push(svc)
  } else {
    selectedServices.value.splice(at, 1)
  }
  // Staff availability and open slots both depend on the whole selection,
  // so anything chosen downstream is stale the moment it changes.
  resetDownstream()
}
```

`resetDownstream()` is the existing reset the file already runs when the service changes (around line 147) — extract it into a named function if it is currently inline.

- [ ] **Step 4: Update the three requests**

Build the query string with repeated params so Laravel reads an array:

```js
const serviceQuery = () => selectedServiceIds.value.map((id) => `service_ids[]=${id}`).join('&')
```

- Staff: `api.get(`${apiBase}/staff?${serviceQuery()}`)` (was `/services/{id}/staff`).
- Slots: replace `service_id: selectedService.value.id` with `service_ids: selectedServiceIds.value` in the params object.
- Book: replace `service_id: selectedService.value.id` with `service_ids: selectedServiceIds.value`.

- [ ] **Step 5: Update the markup**

- Service cards become toggles: `@click="toggleService(svc)"` with the selected class bound to `selectedServiceIds.includes(svc.id)`.
- Under the list, a sticky summary and a Continue button:

```html
<div v-if="selectedServices.length" class="sticky bottom-0 ...">
  <p class="label text-white/60">
    {{ selectedServices.length }} {{ selectedServices.length === 1 ? 'service' : 'services' }}
    · {{ totalDuration }} min · {{ currency }}{{ totalPrice.toFixed(2) }}
  </p>
  <button type="button" class="btn-primary" @click="goToStaffStep">Continue</button>
</div>
```

- The staff step's empty state (currently "No one is available for this service right now. Try another service.") becomes:

```html
No one can do all of these — try removing a service.
```

- The confirmation screen's `<dd>{{ confirmation.service?.name }}</dd>` becomes a list over `confirmation.services`.

- [ ] **Step 6: Run the test to verify it passes**

Run: `cd frontend && npm run test:unit -- src/views/PublicBookingView.spec.js`
Expected: PASS, 2 tests.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/views/PublicBookingView.vue frontend/src/views/PublicBookingView.spec.js
git commit -m "feat: pick multiple services on the public booking site"
```

---

### Task 14: Dashboard appointments form and the tip field

**Files:**
- Modify: `frontend/src/views/AppointmentsView.vue`
- Test: `frontend/src/views/AppointmentsView.spec.js` (create if absent)

**Interfaces:**
- Consumes: `POST /appointments` with `service_ids[]` (Task 5), `POST /appointments/{id}/payments` with `tip_amount` (Task 9).

- [ ] **Step 1: Write the failing test**

`frontend/src/views/AppointmentsView.spec.js`:

```js
import { describe, it, expect } from 'vitest'
import { staffWhoCanDoAll } from './AppointmentsView.vue'

describe('staffWhoCanDoAll', () => {
  const staff = [
    { id: 1, name: 'Alex', services: [{ id: 10 }, { id: 11 }] },
    { id: 2, name: 'Sam', services: [{ id: 10 }] },
    { id: 3, name: 'Unassigned', services: [] },
  ]

  it('keeps only staff who cover every selected service', () => {
    expect(staffWhoCanDoAll(staff, [10, 11]).map((s) => s.name)).toEqual(['Alex'])
  })

  it('returns everyone when nothing is selected', () => {
    expect(staffWhoCanDoAll(staff, [])).toHaveLength(3)
  })
})
```

This requires exporting the helper as a named export from the SFC's `<script>` block (`export function staffWhoCanDoAll(...)` inside a plain `<script>` alongside `<script setup>`).

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd frontend && npm run test:unit -- src/views/AppointmentsView.spec.js`
Expected: FAIL — no such export.

- [ ] **Step 3: Replace the single-service form state**

In `AppointmentsView.vue`:

- `form.service_id: ''` becomes `form.service_ids: []`.
- The `eligibleStaff` computed (around `:140`) and the "no staff assigned" flag (`:148`) both switch to the intersection helper:

```js
export function staffWhoCanDoAll(staff, serviceIds) {
  if (!serviceIds.length) return staff
  return staff.filter((member) =>
    serviceIds.every((id) => (member.services || []).some((sv) => sv.id === Number(id))),
  )
}
```

- Reset (`:162`) and edit-populate (`:194`) become `service_ids: []` and `service_ids: (appt.services || []).map((s) => s.id)`.
- Submit (`:217`) sends `service_ids: form.service_ids.map(Number)`.
- The list cell at `:393` becomes `{{ (appt.services || []).map((s) => s.name).join(', ') || '—' }}`.

- [ ] **Step 4: Replace the service `<select>` with a multi-select**

Replace the single `<select v-model="form.service_id">` block (`:490-497`) with a checkbox list plus a running total:

```html
<fieldset>
  <legend class="label">Services</legend>
  <label v-for="svc in serviceList" :key="svc.id" class="flex items-center gap-2 py-1">
    <input type="checkbox" :value="svc.id" v-model="form.service_ids" />
    <span>{{ serviceLabel(svc) }}</span>
  </label>
  <p v-if="form.service_ids.length" class="mt-2 text-sm text-slate-500">
    {{ form.service_ids.length }} selected · {{ formDuration }} min · {{ formTotal.toFixed(2) }}
  </p>
  <p v-if="formErrors.service_ids" class="mt-1 text-sm text-rose-600">{{ formErrors.service_ids[0] }}</p>
</fieldset>
```

with `formDuration` and `formTotal` computed from `serviceList` filtered by `form.service_ids`.

- [ ] **Step 5: Add the tip field to the payment form**

In the record-payment form, add beside Amount:

```html
<label class="label" for="tip">Tip</label>
<input id="tip" v-model="paymentForm.tip_amount" type="number" step="0.01" min="0" class="input" />
```

Initialise `paymentForm.tip_amount = ''` and send `tip_amount: paymentForm.tip_amount === '' ? 0 : Number(paymentForm.tip_amount)`. Show the invoice's `tips` and `total_collected` beneath the balance.

- [ ] **Step 6: Run the test to verify it passes**

Run: `cd frontend && npm run test:unit -- src/views/AppointmentsView.spec.js`
Expected: PASS, 2 tests.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/views/AppointmentsView.vue frontend/src/views/AppointmentsView.spec.js
git commit -m "feat: multi-service dashboard bookings and a tip at checkout"
```

---

### Task 15: Remaining frontend readers

**Files:**
- Modify: `frontend/src/views/ManageBookingView.vue:200`, `:207`, `:365`
- Modify: `frontend/src/views/CustomerDashboardView.vue:179`, `:214`, `:256`, `:295`
- Modify: `frontend/src/views/ReportsView.vue:209`
- Modify: the payroll view under `frontend/src/views/` (find it with `grep -rln "commission_amount" frontend/src`)

**Interfaces:**
- Consumes: `services[]` on the appointment payloads (Tasks 5–7), `tips_amount` on payroll lines (Task 11).

- [ ] **Step 1: Update `ManageBookingView`**

- `:200` guard becomes `if (!booking.value?.services?.length || !booking.value?.staff?.id) return`.
- `:207` slot params: `service_ids: booking.value.services.map((s) => s.id)`. If the reschedule endpoint derives the duration from the stored window (Task 4), drop the service params from that call entirely and keep only date/staff.
- `:365` becomes a list: `<dd>{{ booking.services.map((s) => s.name).join(', ') }}</dd>`.

- [ ] **Step 2: Update `CustomerDashboardView`**

`Customer/BookingController::present()` now emits `services` — an array of the
line names, in `sort_order` — where it used to emit a single `service` string.
All four readers here interpolate the old key, which Vue renders as an empty
string rather than failing, so nothing surfaces the break but a customer looking
at a blank line where their booking used to be described.

- `:179` and `:214` become `{{ b.services.join(', ') }}`.
- `:256` becomes `{{ rescheduling.services.join(', ') }}`.
- `:295` becomes `{{ reviewing.services.join(', ') }}`.

- [ ] **Step 3: Update `ReportsView`**

The Top services table header at `:209` changes from `Bookings` to `Services booked` — the column now counts service lines, not visits, and an unchanged header would quietly misreport. Leave the second `Bookings` header at `:231` (staff performance) alone; that one still counts visits.

- [ ] **Step 4: Add the payroll Tips column**

In the payroll view, add a `Tips` header and cell between Commission and Total, reading `line.tips_amount`.

- [ ] **Step 5: Run the frontend suite and build**

Run: `cd frontend && npm run test:unit && npm run build`
Expected: PASS, and a clean build.

- [ ] **Step 6: Run the whole backend suite once more**

Run: `cd backend && php artisan test`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add frontend/src
git commit -m "feat: show multi-service bookings and tips across the dashboard"
```

---

## Verification

After Task 15, confirm end to end:

1. `cd backend && php artisan migrate:fresh --seed` completes.
2. `cd backend && php artisan test` — all green.
3. `cd frontend && npm run test:unit && npm run build` — all green.
4. Manually: book two services on the public site, confirm the calendar block spans both durations, take a payment with a tip at the counter, open the invoice (two line items, tips shown separately, balance unaffected), then generate a payroll run for that month and confirm the tip appears in Tips and in Total but not in Earned revenue.
