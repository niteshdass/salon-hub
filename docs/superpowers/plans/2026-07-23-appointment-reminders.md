# Appointment Reminders + WhatsApp/SMS Integration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Send each customer one pre-appointment reminder over a per-org channel (WhatsApp/SMS) at a configurable lead time, via a channel-driver abstraction whose only shipped driver logs (zero cost).

**Architecture:** A new `reminder_settings` table holds per-org config + encrypted credentials. An hourly `reminders:send` command drives `AppointmentReminderService::dispatchDue()`, which selects due appointments (timezone-aware window, status/phone filters), atomically claims each via `reminder_sent_at`, and queues a `SendAppointmentReminder` job. The job resolves a `ReminderChannel` from `ReminderChannelManager` (log driver now) and sends. A tenant-scoped settings API + Vue Settings view manage the config.

**Tech Stack:** Laravel 12 (PHP 8.3), MySQL, database queue, `log` mail driver, Vue 3 + Vite + Pinia + Axios + Tailwind v4. Tests: PHPUnit + `RefreshDatabase`. Design spec: `docs/superpowers/specs/2026-07-23-appointment-reminders-design.md`.

## Global Constraints

- Backend commands run from the `backend/` directory. Frontend commands run from `frontend/`.
- Multi-tenancy: models use `App\Models\Concerns\BelongsToOrganization` (global scope + `organization_id` auto-fill active ONLY when a tenant is bound). In console/queue/tests no tenant is bound, so those queries must pass `organization_id` explicitly.
- App timezone is `UTC`; `now()` returns UTC. Per-org local time uses `Organization.timezone` (default `'UTC'`).
- Appointment times are stored as `H:i:s` strings; `booking_date` is a `date` cast.
- Credentials are secrets: stored via `encrypted:array` cast and NEVER returned in any API response.
- Existing test pattern (mirror it): PHPUnit class extends `Tests\TestCase`, `use RefreshDatabase`, org+owner+token via `createToken('api')->plainTextToken`, requests via `$this->withToken($token)->getJson(...)`.
- Run the full backend suite with `php artisan test`. Run a single test with `php artisan test --filter=method_name`.
- `AppointmentStatus` enum (string-backed): `PENDING`, `CONFIRMED`, `COMPLETED`, `CANCELLED`, `NO_SHOW`.

---

## File Structure

**Backend (create):**
- `backend/database/migrations/2026_07_24_100010_create_reminder_settings_table.php`
- `backend/database/migrations/2026_07_24_100011_add_reminder_sent_at_to_appointments_table.php`
- `backend/app/Models/ReminderSetting.php`
- `backend/app/Reminders/ReminderChannel.php` (interface)
- `backend/app/Reminders/LogReminderChannel.php`
- `backend/app/Reminders/ReminderChannelManager.php`
- `backend/app/Jobs/SendAppointmentReminder.php`
- `backend/app/Services/AppointmentReminderService.php`
- `backend/app/Console/Commands/SendAppointmentReminders.php`
- `backend/app/Http/Controllers/ReminderSettingController.php`
- `backend/app/Http/Requests/Reminder/UpdateReminderSettingRequest.php`
- `backend/tests/Feature/Reminders/ReminderSettingTest.php` (model + API)
- `backend/tests/Feature/Reminders/AppointmentReminderServiceTest.php`
- `backend/tests/Feature/Reminders/SendAppointmentReminderJobTest.php`

**Backend (modify):**
- `backend/bootstrap/app.php` — register hourly schedule.
- `backend/routes/api.php` — add settings routes.

**Frontend (create):**
- `frontend/src/views/SettingsView.vue`

**Frontend (modify):**
- `frontend/src/router/index.js` — add `/settings` child route.
- `frontend/src/layouts/DashboardLayout.vue` — add Settings nav item.

---

## Task 1: Data model — `reminder_settings` table, `reminder_sent_at`, `ReminderSetting` model

**Files:**
- Create: `backend/database/migrations/2026_07_24_100010_create_reminder_settings_table.php`
- Create: `backend/database/migrations/2026_07_24_100011_add_reminder_sent_at_to_appointments_table.php`
- Create: `backend/app/Models/ReminderSetting.php`
- Test: `backend/tests/Feature/Reminders/ReminderSettingTest.php`

**Interfaces:**
- Produces: table `reminder_settings(id, organization_id unique, enabled bool, channel string, lead_hours smallint, credentials text nullable, timestamps)`; column `appointments.reminder_sent_at` (nullable timestamp).
- Produces: `App\Models\ReminderSetting` with `$fillable = ['organization_id','enabled','channel','lead_hours','credentials']`, casts `enabled=>boolean`, `lead_hours=>integer`, `credentials=>encrypted:array`; `organization(): BelongsTo`.
- Produces: `App\Models\Appointment` gains `reminder_sent_at` in `$fillable` and cast `reminder_sent_at=>datetime`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Reminders/ReminderSettingTest.php`:

```php
<?php

namespace Tests\Feature\Reminders;

use App\Models\Organization;
use App\Models\ReminderSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReminderSettingTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrg(string $slug): Organization
    {
        return Organization::create([
            'uuid' => (string) Str::uuid(),
            'name' => ucfirst($slug),
            'slug' => $slug,
            'email' => "owner@{$slug}.test",
            'timezone' => 'UTC',
            'subscription_plan' => 'free',
            'status' => 'active',
        ]);
    }

    public function test_credentials_are_encrypted_at_rest_and_decrypted_on_read(): void
    {
        $org = $this->makeOrg('alpha');

        $settings = ReminderSetting::create([
            'organization_id' => $org->id,
            'enabled' => true,
            'channel' => 'whatsapp',
            'lead_hours' => 24,
            'credentials' => ['access_token' => 'super-secret-token'],
        ]);

        // Model round-trips the array.
        $this->assertSame('super-secret-token', $settings->fresh()->credentials['access_token']);
        $this->assertTrue($settings->fresh()->enabled);
        $this->assertSame(24, $settings->fresh()->lead_hours);

        // Raw column value is NOT the plaintext (it is encrypted).
        $raw = DB::table('reminder_settings')->where('id', $settings->id)->value('credentials');
        $this->assertStringNotContainsString('super-secret-token', $raw);
    }

    public function test_appointment_has_reminder_sent_at_column(): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasColumn('appointments', 'reminder_sent_at')
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=ReminderSettingTest`
Expected: FAIL — `reminder_settings` table / `ReminderSetting` class does not exist.

- [ ] **Step 3: Create the `reminder_settings` migration**

Create `backend/database/migrations/2026_07_24_100010_create_reminder_settings_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-organization pre-appointment reminder configuration. One row per
     * org. Credentials are stored via the model's encrypted cast, so the
     * column is free-form text.
     */
    public function up(): void
    {
        Schema::create('reminder_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('channel')->default('whatsapp');
            $table->unsignedSmallInteger('lead_hours')->default(24);
            $table->text('credentials')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_settings');
    }
};
```

- [ ] **Step 4: Create the `reminder_sent_at` migration**

Create `backend/database/migrations/2026_07_24_100011_add_reminder_sent_at_to_appointments_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dedupe flag for the single pre-appointment reminder. Null = not yet
     * reminded; set = claimed/sent. Also the atomic claim guard.
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->timestamp('reminder_sent_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('reminder_sent_at');
        });
    }
};
```

- [ ] **Step 5: Create the `ReminderSetting` model**

Create `backend/app/Models/ReminderSetting.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReminderSetting extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id',
        'enabled',
        'channel',
        'lead_hours',
        'credentials',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'lead_hours' => 'integer',
            'credentials' => 'encrypted:array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
```

- [ ] **Step 6: Add `reminder_sent_at` to the Appointment model**

Modify `backend/app/Models/Appointment.php`. In `$fillable`, add `'reminder_sent_at'` after `'status'`:

```php
    protected $fillable = [
        'organization_id',
        'public_token',
        'branch_id',
        'customer_id',
        'staff_id',
        'service_id',
        'booking_date',
        'start_time',
        'end_time',
        'status',
        'reminder_sent_at',
        'notes',
    ];
```

And in `casts()`, add the datetime cast:

```php
    protected function casts(): array
    {
        return [
            'booking_date' => 'date',
            'status' => AppointmentStatus::class,
            'reminder_sent_at' => 'datetime',
        ];
    }
```

- [ ] **Step 7: Run test to verify it passes**

Run: `cd backend && php artisan test --filter=ReminderSettingTest`
Expected: PASS (2 tests).

- [ ] **Step 8: Commit**

```bash
cd backend && git add app/Models/ReminderSetting.php app/Models/Appointment.php database/migrations/2026_07_24_100010_create_reminder_settings_table.php database/migrations/2026_07_24_100011_add_reminder_sent_at_to_appointments_table.php tests/Feature/Reminders/ReminderSettingTest.php
git commit -m "feat: reminder_settings table + reminder_sent_at flag"
```

---

## Task 2: Channel abstraction — interface, log driver, manager

**Files:**
- Create: `backend/app/Reminders/ReminderChannel.php`
- Create: `backend/app/Reminders/LogReminderChannel.php`
- Create: `backend/app/Reminders/ReminderChannelManager.php`
- Test: `backend/tests/Feature/Reminders/ReminderChannelManagerTest.php`

**Interfaces:**
- Produces: `interface App\Reminders\ReminderChannel { public function send(string $to, string $message): void; }`
- Produces: `class App\Reminders\LogReminderChannel implements ReminderChannel` — writes `"[reminder] to={$to} :: {$message}"` at info level.
- Produces: `class App\Reminders\ReminderChannelManager { public function for(string $channel): ReminderChannel; }` — returns the log driver for every channel value this iteration.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Reminders/ReminderChannelManagerTest.php`:

```php
<?php

namespace Tests\Feature\Reminders;

use App\Reminders\ReminderChannel;
use App\Reminders\ReminderChannelManager;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ReminderChannelManagerTest extends TestCase
{
    public function test_manager_resolves_a_reminder_channel_for_each_channel_value(): void
    {
        $manager = app(ReminderChannelManager::class);

        $this->assertInstanceOf(ReminderChannel::class, $manager->for('whatsapp'));
        $this->assertInstanceOf(ReminderChannel::class, $manager->for('sms'));
    }

    public function test_log_channel_writes_recipient_and_message(): void
    {
        Log::shouldReceive('info')
            ->once()
            ->with('[reminder] to=+15550100 :: Hello there');

        app(ReminderChannelManager::class)->for('whatsapp')->send('+15550100', 'Hello there');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=ReminderChannelManagerTest`
Expected: FAIL — classes in `App\Reminders` do not exist.

- [ ] **Step 3: Create the interface**

Create `backend/app/Reminders/ReminderChannel.php`:

```php
<?php

namespace App\Reminders;

/**
 * A delivery channel for a pre-appointment reminder. Implementations send a
 * single plain-text message to one recipient address (phone number).
 */
interface ReminderChannel
{
    public function send(string $to, string $message): void;
}
```

- [ ] **Step 4: Create the log driver**

Create `backend/app/Reminders/LogReminderChannel.php`:

```php
<?php

namespace App\Reminders;

use Illuminate\Support\Facades\Log;

/**
 * Zero-cost reminder channel: records the recipient and message to the log.
 * The only driver shipped in this iteration; real WhatsApp / SMS drivers
 * implement the same interface later.
 */
class LogReminderChannel implements ReminderChannel
{
    public function send(string $to, string $message): void
    {
        Log::info("[reminder] to={$to} :: {$message}");
    }
}
```

- [ ] **Step 5: Create the manager**

Create `backend/app/Reminders/ReminderChannelManager.php`:

```php
<?php

namespace App\Reminders;

/**
 * Resolves the delivery driver for an org's configured channel. This
 * iteration ships only the log driver, so every channel value ('whatsapp',
 * 'sms') resolves to it. Real drivers register here later with no change to
 * callers.
 */
class ReminderChannelManager
{
    public function for(string $channel): ReminderChannel
    {
        return app(LogReminderChannel::class);
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `cd backend && php artisan test --filter=ReminderChannelManagerTest`
Expected: PASS (2 tests).

- [ ] **Step 7: Commit**

```bash
cd backend && git add app/Reminders tests/Feature/Reminders/ReminderChannelManagerTest.php
git commit -m "feat: reminder channel abstraction with log driver"
```

---

## Task 3: Reminder engine — service + queued job

**Files:**
- Create: `backend/app/Jobs/SendAppointmentReminder.php`
- Create: `backend/app/Services/AppointmentReminderService.php`
- Test: `backend/tests/Feature/Reminders/AppointmentReminderServiceTest.php`
- Test: `backend/tests/Feature/Reminders/SendAppointmentReminderJobTest.php`

**Interfaces:**
- Consumes: `ReminderSetting` (Task 1), `ReminderChannelManager` (Task 2), `Appointment.reminder_sent_at` (Task 1).
- Produces: `class App\Services\AppointmentReminderService { public function __construct(); public function dispatchDue(): void; }` — iterates enabled orgs, claims + queues due appointments.
- Produces: `class App\Jobs\SendAppointmentReminder implements ShouldQueue { public function __construct(public int $appointmentId); public function handle(ReminderChannelManager $channels): void; }`.

- [ ] **Step 1: Write the failing service test**

Create `backend/tests/Feature/Reminders/AppointmentReminderServiceTest.php`:

```php
<?php

namespace Tests\Feature\Reminders;

use App\Jobs\SendAppointmentReminder;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\ReminderSetting;
use App\Models\Service;
use App\Models\User;
use App\Services\AppointmentReminderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class AppointmentReminderServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrg(string $slug, bool $enabled = true, int $lead = 24, string $tz = 'UTC'): Organization
    {
        $org = Organization::create([
            'uuid' => (string) Str::uuid(),
            'name' => ucfirst($slug),
            'slug' => $slug,
            'email' => "owner@{$slug}.test",
            'timezone' => $tz,
            'subscription_plan' => 'free',
            'status' => 'active',
        ]);

        ReminderSetting::create([
            'organization_id' => $org->id,
            'enabled' => $enabled,
            'channel' => 'whatsapp',
            'lead_hours' => $lead,
        ]);

        return $org;
    }

    /**
     * Build one appointment with all relations, explicit organization_id
     * (no tenant is bound in tests).
     */
    private function makeAppointment(Organization $org, Carbon $startsAt, array $overrides = []): Appointment
    {
        $branch = Branch::create(['organization_id' => $org->id, 'name' => 'Main']);
        $staff = User::create([
            'organization_id' => $org->id,
            'name' => 'Stylist',
            'email' => 'stylist@'.$org->slug.'.test',
            'password' => 'secret1234',
            'role' => 'staff',
            'status' => 'active',
        ]);
        $service = Service::create([
            'organization_id' => $org->id,
            'name' => 'Haircut',
            'duration' => 30,
            'price' => 20,
            'status' => 'active',
        ]);
        $customer = Customer::create(array_merge([
            'organization_id' => $org->id,
            'name' => 'Casey',
            'phone' => '+15550100',
            'email' => 'casey@example.test',
        ], $overrides['customer'] ?? []));

        return Appointment::create(array_merge([
            'organization_id' => $org->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'staff_id' => $staff->id,
            'service_id' => $service->id,
            'booking_date' => $startsAt->toDateString(),
            'start_time' => $startsAt->format('H:i:s'),
            'end_time' => $startsAt->copy()->addMinutes(30)->format('H:i:s'),
            'status' => 'confirmed',
        ], $overrides['appointment'] ?? []));
    }

    public function test_due_appointment_is_claimed_and_queued(): void
    {
        Queue::fake();
        $org = $this->makeOrg('alpha', enabled: true, lead: 24);
        $appt = $this->makeAppointment($org, Carbon::now()->addHours(3));

        app(AppointmentReminderService::class)->dispatchDue();

        Queue::assertPushed(SendAppointmentReminder::class, fn ($job) => $job->appointmentId === $appt->id);
        $this->assertNotNull($appt->fresh()->reminder_sent_at);
    }

    public function test_appointment_outside_lead_window_is_not_queued(): void
    {
        Queue::fake();
        $org = $this->makeOrg('beta', enabled: true, lead: 24);
        // 30h away, lead is 24h -> outside window.
        $appt = $this->makeAppointment($org, Carbon::now()->addHours(30));

        app(AppointmentReminderService::class)->dispatchDue();

        Queue::assertNothingPushed();
        $this->assertNull($appt->fresh()->reminder_sent_at);
    }

    public function test_past_appointment_is_not_queued(): void
    {
        Queue::fake();
        $org = $this->makeOrg('gamma');
        $appt = $this->makeAppointment($org, Carbon::now()->subHour());

        app(AppointmentReminderService::class)->dispatchDue();

        Queue::assertNothingPushed();
        $this->assertNull($appt->fresh()->reminder_sent_at);
    }

    public function test_already_reminded_appointment_is_not_requeued(): void
    {
        Queue::fake();
        $org = $this->makeOrg('delta');
        $appt = $this->makeAppointment($org, Carbon::now()->addHours(2), [
            'appointment' => ['reminder_sent_at' => Carbon::now()->subHour()],
        ]);

        app(AppointmentReminderService::class)->dispatchDue();

        Queue::assertNothingPushed();
    }

    public function test_disabled_org_sends_nothing(): void
    {
        Queue::fake();
        $org = $this->makeOrg('epsilon', enabled: false);
        $this->makeAppointment($org, Carbon::now()->addHours(2));

        app(AppointmentReminderService::class)->dispatchDue();

        Queue::assertNothingPushed();
    }

    public function test_appointment_without_phone_is_skipped(): void
    {
        Queue::fake();
        $org = $this->makeOrg('zeta');
        $appt = $this->makeAppointment($org, Carbon::now()->addHours(2), [
            'customer' => ['phone' => null],
        ]);

        app(AppointmentReminderService::class)->dispatchDue();

        Queue::assertNothingPushed();
        $this->assertNull($appt->fresh()->reminder_sent_at);
    }

    public function test_cancelled_appointment_is_skipped(): void
    {
        Queue::fake();
        $org = $this->makeOrg('eta');
        $appt = $this->makeAppointment($org, Carbon::now()->addHours(2), [
            'appointment' => ['status' => 'cancelled'],
        ]);

        app(AppointmentReminderService::class)->dispatchDue();

        Queue::assertNothingPushed();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=AppointmentReminderServiceTest`
Expected: FAIL — `App\Services\AppointmentReminderService` / `App\Jobs\SendAppointmentReminder` do not exist.

- [ ] **Step 3: Create the queued job**

Create `backend/app/Jobs/SendAppointmentReminder.php`:

```php
<?php

namespace App\Jobs;

use App\Models\Appointment;
use App\Models\ReminderSetting;
use App\Reminders\ReminderChannelManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers one pre-appointment reminder to the customer over the org's
 * configured channel. The claim (reminder_sent_at) already happened when the
 * service dispatched this job, so delivery is best-effort: a failure is logged
 * and swallowed rather than retried.
 */
class SendAppointmentReminder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $appointmentId)
    {
    }

    public function handle(ReminderChannelManager $channels): void
    {
        $appointment = Appointment::with(['customer', 'service', 'organization'])
            ->find($this->appointmentId);

        $phone = $appointment?->customer?->phone;
        if (! $appointment || ! $phone) {
            return;
        }

        $settings = ReminderSetting::where('organization_id', $appointment->organization_id)->first();
        if (! $settings || ! $settings->enabled) {
            return;
        }

        try {
            $channels->for($settings->channel)->send($phone, $this->buildMessage($appointment));
        } catch (Throwable $e) {
            Log::error('Appointment reminder send failed', [
                'appointment_id' => $appointment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function buildMessage(Appointment $appointment): string
    {
        $tz = $appointment->organization?->timezone ?: 'UTC';
        $startsAt = Carbon::parse(
            $appointment->booking_date->toDateString().' '.$appointment->start_time,
            $tz,
        );
        $salon = $appointment->organization?->name ?? 'the salon';
        $service = $appointment->service?->name ?? 'your appointment';

        return sprintf(
            'Reminder: %s at %s on %s, %s. See you soon!',
            $service,
            $salon,
            $startsAt->format('l, F j'),
            $startsAt->format('g:i A'),
        );
    }
}
```

- [ ] **Step 4: Create the service**

Create `backend/app/Services/AppointmentReminderService.php`:

```php
<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Jobs\SendAppointmentReminder;
use App\Models\Appointment;
use App\Models\Organization;
use App\Models\ReminderSetting;
use Illuminate\Support\Carbon;

/**
 * Finds appointments whose start falls within their org's configured lead
 * window and queues exactly one reminder each. Runs in the console/queue
 * context where no tenant is bound, so every query filters organization_id
 * explicitly.
 */
class AppointmentReminderService
{
    public function dispatchDue(): void
    {
        ReminderSetting::where('enabled', true)
            ->with('organization')
            ->get()
            ->each(fn (ReminderSetting $settings) => $this->dispatchForOrg($settings));
    }

    private function dispatchForOrg(ReminderSetting $settings): void
    {
        $org = $settings->organization;
        if (! $org instanceof Organization) {
            return;
        }

        $tz = $org->timezone ?: 'UTC';
        $now = Carbon::now();
        $windowEnd = $now->copy()->addHours($settings->lead_hours);

        // Bound the DB scan by date (local); the exact window is checked in PHP.
        $fromDate = $now->copy()->timezone($tz)->toDateString();
        $toDate = $windowEnd->copy()->timezone($tz)->addDay()->toDateString();

        Appointment::where('organization_id', $org->id)
            ->whereIn('status', [AppointmentStatus::PENDING->value, AppointmentStatus::CONFIRMED->value])
            ->whereNull('reminder_sent_at')
            ->whereBetween('booking_date', [$fromDate, $toDate])
            ->with('customer')
            ->get()
            ->each(function (Appointment $appointment) use ($tz, $now, $windowEnd) {
                if (! $appointment->customer?->phone) {
                    return;
                }

                $startsAt = Carbon::parse(
                    $appointment->booking_date->toDateString().' '.$appointment->start_time,
                    $tz,
                );

                // Only the (now, now + lead] window; skip past + too-far.
                if ($startsAt->lessThanOrEqualTo($now) || $startsAt->greaterThan($windowEnd)) {
                    return;
                }

                // Atomic claim: only the run that flips the null flag dispatches,
                // so overlapping hourly runs cannot double-send.
                $claimed = Appointment::where('id', $appointment->id)
                    ->whereNull('reminder_sent_at')
                    ->update(['reminder_sent_at' => $now]);

                if ($claimed === 1) {
                    SendAppointmentReminder::dispatch($appointment->id);
                }
            });
    }
}
```

- [ ] **Step 5: Run service test to verify it passes**

Run: `cd backend && php artisan test --filter=AppointmentReminderServiceTest`
Expected: PASS (7 tests).

- [ ] **Step 6: Write the failing job test**

Create `backend/tests/Feature/Reminders/SendAppointmentReminderJobTest.php`:

```php
<?php

namespace Tests\Feature\Reminders;

use App\Jobs\SendAppointmentReminder;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\ReminderSetting;
use App\Models\Service;
use App\Models\User;
use App\Reminders\ReminderChannel;
use App\Reminders\ReminderChannelManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class SendAppointmentReminderJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_sends_reminder_message_over_the_resolved_channel(): void
    {
        // Record every send() the job makes through a fake channel + manager.
        $sent = [];
        $channel = new class($sent) implements ReminderChannel {
            public function __construct(public array &$sent)
            {
            }

            public function send(string $to, string $message): void
            {
                $this->sent[] = ['to' => $to, 'message' => $message];
            }
        };
        $manager = new class($channel) extends ReminderChannelManager {
            public function __construct(private ReminderChannel $channel)
            {
            }

            public function for(string $channel): ReminderChannel
            {
                return $this->channel;
            }
        };
        $this->app->instance(ReminderChannelManager::class, $manager);

        $org = Organization::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Glow Bar',
            'slug' => 'glow-bar',
            'email' => 'owner@glow-bar.test',
            'timezone' => 'UTC',
            'subscription_plan' => 'free',
            'status' => 'active',
        ]);
        ReminderSetting::create([
            'organization_id' => $org->id,
            'enabled' => true,
            'channel' => 'whatsapp',
            'lead_hours' => 24,
        ]);
        $branch = Branch::create(['organization_id' => $org->id, 'name' => 'Main']);
        $staff = User::create([
            'organization_id' => $org->id, 'name' => 'Stylist',
            'email' => 'stylist@glow-bar.test', 'password' => 'secret1234',
            'role' => 'staff', 'status' => 'active',
        ]);
        $service = Service::create([
            'organization_id' => $org->id, 'name' => 'Haircut',
            'duration' => 30, 'price' => 20, 'status' => 'active',
        ]);
        $customer = Customer::create([
            'organization_id' => $org->id, 'name' => 'Casey',
            'phone' => '+15550100', 'email' => 'casey@example.test',
        ]);
        $appointment = Appointment::create([
            'organization_id' => $org->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'staff_id' => $staff->id,
            'service_id' => $service->id,
            'booking_date' => Carbon::parse('2026-08-01')->toDateString(),
            'start_time' => '14:30:00',
            'end_time' => '15:00:00',
            'status' => 'confirmed',
        ]);

        (new SendAppointmentReminder($appointment->id))->handle($manager);

        $this->assertCount(1, $sent);
        $this->assertSame('+15550100', $sent[0]['to']);
        $this->assertStringContainsString('Haircut', $sent[0]['message']);
        $this->assertStringContainsString('Glow Bar', $sent[0]['message']);
        $this->assertStringContainsString('2:30 PM', $sent[0]['message']);
    }
}
```

- [ ] **Step 7: Run job test to verify it passes**

Run: `cd backend && php artisan test --filter=SendAppointmentReminderJobTest`
Expected: PASS (1 test). (Implementation from Steps 3–4 already covers it.)

- [ ] **Step 8: Commit**

```bash
cd backend && git add app/Jobs/SendAppointmentReminder.php app/Services/AppointmentReminderService.php tests/Feature/Reminders/AppointmentReminderServiceTest.php tests/Feature/Reminders/SendAppointmentReminderJobTest.php
git commit -m "feat: appointment reminder engine (service + queued job)"
```

---

## Task 4: Command + hourly scheduler

**Files:**
- Create: `backend/app/Console/Commands/SendAppointmentReminders.php`
- Modify: `backend/bootstrap/app.php`
- Test: `backend/tests/Feature/Reminders/RemindersCommandTest.php`

**Interfaces:**
- Consumes: `AppointmentReminderService` (Task 3).
- Produces: artisan command signature `reminders:send`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Reminders/RemindersCommandTest.php`:

```php
<?php

namespace Tests\Feature\Reminders;

use App\Services\AppointmentReminderService;
use Mockery;
use Tests\TestCase;

class RemindersCommandTest extends TestCase
{
    public function test_command_invokes_the_reminder_service(): void
    {
        $mock = Mockery::mock(AppointmentReminderService::class);
        $mock->shouldReceive('dispatchDue')->once();
        $this->app->instance(AppointmentReminderService::class, $mock);

        $this->artisan('reminders:send')->assertExitCode(0);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=RemindersCommandTest`
Expected: FAIL — command `reminders:send` is not defined.

- [ ] **Step 3: Create the command**

Create `backend/app/Console/Commands/SendAppointmentReminders.php`:

```php
<?php

namespace App\Console\Commands;

use App\Services\AppointmentReminderService;
use Illuminate\Console\Command;

class SendAppointmentReminders extends Command
{
    protected $signature = 'reminders:send';

    protected $description = 'Queue due pre-appointment reminders for all organizations';

    public function handle(AppointmentReminderService $service): int
    {
        $service->dispatchDue();

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --filter=RemindersCommandTest`
Expected: PASS (1 test).

- [ ] **Step 5: Register the hourly schedule**

Modify `backend/bootstrap/app.php`. Add the import near the top (after the existing `use` lines):

```php
use Illuminate\Console\Scheduling\Schedule;
```

Then add a `->withSchedule(...)` call to the builder chain, immediately after the closing `)` of `->withRouting(...)` and before `->withMiddleware(`:

```php
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('reminders:send')->hourly();
    })
```

- [ ] **Step 6: Verify the schedule is registered**

Run: `cd backend && php artisan schedule:list`
Expected: output lists an entry running `reminders:send` hourly (e.g. `0 * * * *`).

- [ ] **Step 7: Commit**

```bash
cd backend && git add app/Console/Commands/SendAppointmentReminders.php bootstrap/app.php tests/Feature/Reminders/RemindersCommandTest.php
git commit -m "feat: reminders:send command + hourly schedule"
```

---

## Task 5: Settings API — controller, request, routes

**Files:**
- Create: `backend/app/Http/Controllers/ReminderSettingController.php`
- Create: `backend/app/Http/Requests/Reminder/UpdateReminderSettingRequest.php`
- Modify: `backend/routes/api.php`
- Test: add methods to `backend/tests/Feature/Reminders/ReminderSettingTest.php` (from Task 1)

**Interfaces:**
- Consumes: `ReminderSetting` (Task 1), `App\Tenancy\CurrentTenant`.
- Produces: `GET /api/settings/reminders` → `{ data: { enabled, channel, lead_hours, has_credentials: { whatsapp, sms } } }`.
- Produces: `PUT /api/settings/reminders` → same shape. Never returns secret values.

- [ ] **Step 1: Write the failing tests**

Append these methods to the `ReminderSettingTest` class in `backend/tests/Feature/Reminders/ReminderSettingTest.php`. Also add these imports at the top of that file (below the existing `use` lines):

```php
use App\Models\User;
```

Methods:

```php
    /**
     * @return array{0: Organization, 1: string}
     */
    private function orgWithToken(string $slug): array
    {
        $org = $this->makeOrg($slug);
        $owner = User::create([
            'organization_id' => $org->id,
            'name' => "{$slug} owner",
            'email' => "owner-{$slug}@{$slug}.test",
            'password' => 'secret1234',
            'role' => 'owner',
            'status' => 'active',
        ]);

        return [$org, $owner->createToken('api')->plainTextToken];
    }

    public function test_get_returns_defaults_when_no_settings_row_exists(): void
    {
        [, $token] = $this->orgWithToken('showdefaults');

        $res = $this->withToken($token)->getJson('/api/settings/reminders');

        $res->assertOk();
        $res->assertJsonPath('data.enabled', false);
        $res->assertJsonPath('data.channel', 'whatsapp');
        $res->assertJsonPath('data.lead_hours', 24);
        $res->assertJsonPath('data.has_credentials.whatsapp', false);
        $res->assertJsonPath('data.has_credentials.sms', false);
    }

    public function test_put_persists_settings_and_never_returns_secret(): void
    {
        [$org, $token] = $this->orgWithToken('savecreds');

        $res = $this->withToken($token)->putJson('/api/settings/reminders', [
            'enabled' => true,
            'channel' => 'whatsapp',
            'lead_hours' => 48,
            'credentials' => [
                'phone_number_id' => '123456',
                'access_token' => 'top-secret',
                'template_name' => 'reminder_util',
            ],
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.enabled', true);
        $res->assertJsonPath('data.lead_hours', 48);
        $res->assertJsonPath('data.has_credentials.whatsapp', true);
        // Secret value is never present anywhere in the response body.
        $this->assertStringNotContainsString('top-secret', $res->getContent());

        $this->assertDatabaseHas('reminder_settings', [
            'organization_id' => $org->id,
            'enabled' => true,
            'lead_hours' => 48,
        ]);
    }

    public function test_put_with_blank_credential_preserves_stored_secret(): void
    {
        [$org, $token] = $this->orgWithToken('preserve');

        // First save writes the secret.
        $this->withToken($token)->putJson('/api/settings/reminders', [
            'enabled' => true,
            'channel' => 'whatsapp',
            'lead_hours' => 24,
            'credentials' => ['access_token' => 'keep-me'],
        ])->assertOk();

        // Second save re-submits with the secret field blank (masked form).
        $this->withToken($token)->putJson('/api/settings/reminders', [
            'enabled' => true,
            'channel' => 'whatsapp',
            'lead_hours' => 12,
            'credentials' => ['access_token' => ''],
        ])->assertOk();

        $stored = ReminderSetting::where('organization_id', $org->id)->first();
        $this->assertSame('keep-me', $stored->credentials['access_token']);
        $this->assertSame(12, $stored->lead_hours);
    }

    public function test_settings_are_tenant_isolated(): void
    {
        [, $tokenA] = $this->orgWithToken('tenanta');
        [$orgB, $tokenB] = $this->orgWithToken('tenantb');

        $this->withToken($tokenB)->putJson('/api/settings/reminders', [
            'enabled' => true, 'channel' => 'sms', 'lead_hours' => 6,
        ])->assertOk();

        // Tenant A still sees its own defaults, not B's row.
        $this->withToken($tokenA)->getJson('/api/settings/reminders')
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.channel', 'whatsapp');

        $this->assertDatabaseHas('reminder_settings', [
            'organization_id' => $orgB->id,
            'channel' => 'sms',
        ]);
    }

    public function test_put_validates_channel_and_lead_hours(): void
    {
        [, $token] = $this->orgWithToken('validate');

        $this->withToken($token)->putJson('/api/settings/reminders', [
            'enabled' => true,
            'channel' => 'carrier-pigeon',
            'lead_hours' => 999,
        ])->assertStatus(422)->assertJsonValidationErrors(['channel', 'lead_hours']);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && php artisan test --filter=ReminderSettingTest`
Expected: FAIL — route `/api/settings/reminders` not defined (404 / method missing).

- [ ] **Step 3: Create the FormRequest**

Create `backend/app/Http/Requests/Reminder/UpdateReminderSettingRequest.php`:

```php
<?php

namespace App\Http\Requests\Reminder;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateReminderSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'channel' => ['required', Rule::in(['whatsapp', 'sms'])],
            'lead_hours' => ['required', 'integer', 'min:1', 'max:168'],
            'credentials' => ['nullable', 'array'],
            'credentials.phone_number_id' => ['nullable', 'string', 'max:255'],
            'credentials.access_token' => ['nullable', 'string', 'max:1024'],
            'credentials.template_name' => ['nullable', 'string', 'max:255'],
            'credentials.provider' => ['nullable', 'string', 'max:255'],
            'credentials.from' => ['nullable', 'string', 'max:255'],
            'credentials.api_key' => ['nullable', 'string', 'max:1024'],
        ];
    }
}
```

- [ ] **Step 4: Create the controller**

Create `backend/app/Http/Controllers/ReminderSettingController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\Reminder\UpdateReminderSettingRequest;
use App\Models\ReminderSetting;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;

/**
 * Tenant-scoped pre-appointment reminder configuration. The tenant is bound
 * by the `tenant` middleware, so reads auto-scope to the current org and
 * organization_id is auto-filled on create. Secret credential values are
 * never returned — only per-channel presence booleans.
 */
class ReminderSettingController extends Controller
{
    public function show(): JsonResponse
    {
        $settings = ReminderSetting::query()->first();

        return response()->json(['data' => $this->payload($settings)]);
    }

    public function update(UpdateReminderSettingRequest $request): JsonResponse
    {
        $data = $request->validated();
        $orgId = app(CurrentTenant::class)->id();

        $settings = ReminderSetting::firstOrNew(['organization_id' => $orgId]);
        $settings->enabled = $data['enabled'];
        $settings->channel = $data['channel'];
        $settings->lead_hours = $data['lead_hours'];

        // Merge credentials: a blank/absent field keeps the stored secret, so
        // re-saving a masked form never wipes existing keys.
        $credentials = $settings->credentials ?? [];
        foreach ($data['credentials'] ?? [] as $key => $value) {
            if ($value !== null && $value !== '') {
                $credentials[$key] = $value;
            }
        }
        $settings->credentials = $credentials !== [] ? $credentials : null;
        $settings->save();

        return response()->json(['data' => $this->payload($settings->fresh())]);
    }

    /**
     * Safe public shape: config values + per-channel credential presence.
     * Never includes secret values.
     *
     * @return array<string, mixed>
     */
    private function payload(?ReminderSetting $settings): array
    {
        $credentials = $settings?->credentials ?? [];

        return [
            'enabled' => (bool) ($settings?->enabled ?? false),
            'channel' => $settings?->channel ?: 'whatsapp',
            'lead_hours' => (int) ($settings?->lead_hours ?? 24),
            'has_credentials' => [
                'whatsapp' => filled($credentials['access_token'] ?? null),
                'sms' => filled($credentials['api_key'] ?? null),
            ],
        ];
    }
}
```

- [ ] **Step 5: Add the routes**

Modify `backend/routes/api.php`. Add the import with the other controller imports:

```php
use App\Http\Controllers\ReminderSettingController;
```

Inside the `Route::middleware(['auth:sanctum', 'tenant'])->group(function () {` block, after the `Route::apiResource('staff', ...)` line, add:

```php
    Route::get('settings/reminders', [ReminderSettingController::class, 'show']);
    Route::put('settings/reminders', [ReminderSettingController::class, 'update']);
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `cd backend && php artisan test --filter=ReminderSettingTest`
Expected: PASS (7 tests total in the class).

- [ ] **Step 7: Commit**

```bash
cd backend && git add app/Http/Controllers/ReminderSettingController.php app/Http/Requests/Reminder/UpdateReminderSettingRequest.php routes/api.php tests/Feature/Reminders/ReminderSettingTest.php
git commit -m "feat: tenant-scoped reminder settings API"
```

---

## Task 6: Frontend — Settings view, route, nav

**Files:**
- Create: `frontend/src/views/SettingsView.vue`
- Modify: `frontend/src/router/index.js`
- Modify: `frontend/src/layouts/DashboardLayout.vue`

**Interfaces:**
- Consumes: `GET /api/settings/reminders`, `PUT /api/settings/reminders` (Task 5); `@/lib/api`; `parseApiError` from `@/lib/errors`.

> No JS test runner exists in this project. Verification is `npm run build` (compiles cleanly) plus the end-to-end browser check in Task 7.

- [ ] **Step 1: Create the Settings view**

Create `frontend/src/views/SettingsView.vue`:

```vue
<script setup>
import { onMounted, reactive, ref } from 'vue'
import api from '@/lib/api'
import { parseApiError } from '@/lib/errors'

const loading = ref(false)
const saving = ref(false)
const loadError = ref('')
const formMessage = ref('')
const formErrors = ref({})
const savedOk = ref(false)

// Per-channel credential presence, reported by the API (never the secrets).
const hasCredentials = reactive({ whatsapp: false, sms: false })

const form = reactive({
  enabled: false,
  channel: 'whatsapp',
  lead_hours: 24,
  credentials: {
    phone_number_id: '',
    access_token: '',
    template_name: '',
    provider: '',
    from: '',
    api_key: '',
  },
})

function fieldError(key) {
  const e = formErrors.value[key]
  return Array.isArray(e) ? e[0] : e || ''
}

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    const { data } = await api.get('/settings/reminders')
    const s = data.data || {}
    form.enabled = !!s.enabled
    form.channel = s.channel || 'whatsapp'
    form.lead_hours = s.lead_hours ?? 24
    hasCredentials.whatsapp = !!s.has_credentials?.whatsapp
    hasCredentials.sms = !!s.has_credentials?.sms
  } catch (err) {
    loadError.value = parseApiError(err, 'Could not load settings.').message
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  savedOk.value = false
  formMessage.value = ''
  formErrors.value = {}
  try {
    // Only send credential fields the user actually typed; blanks are omitted
    // so the backend keeps any stored secret.
    const credentials = {}
    for (const [k, v] of Object.entries(form.credentials)) {
      if (v !== '' && v != null) credentials[k] = v
    }

    const { data } = await api.put('/settings/reminders', {
      enabled: form.enabled,
      channel: form.channel,
      lead_hours: Number(form.lead_hours),
      credentials,
    })
    const s = data.data || {}
    hasCredentials.whatsapp = !!s.has_credentials?.whatsapp
    hasCredentials.sms = !!s.has_credentials?.sms
    // Clear the secret inputs after a successful save (they are write-only).
    form.credentials.access_token = ''
    form.credentials.api_key = ''
    savedOk.value = true
  } catch (err) {
    const parsed = parseApiError(err, 'Could not save settings.')
    formMessage.value = parsed.message
    formErrors.value = parsed.errors
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-2xl font-semibold text-slate-900">Settings</h1>
      <p class="mt-1 text-sm text-slate-500">Appointment reminders and channel connection.</p>
    </div>

    <p v-if="loadError" class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">
      {{ loadError }}
    </p>

    <form
      v-if="!loading"
      class="max-w-2xl space-y-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"
      @submit.prevent="save"
    >
      <div class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800">
        Reminders run in log/test mode until a live provider is connected.
      </div>

      <!-- Enable -->
      <label class="flex items-center gap-3">
        <input v-model="form.enabled" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-indigo-600" />
        <span class="text-sm font-medium text-slate-800">Send pre-appointment reminders</span>
      </label>

      <!-- Channel -->
      <div>
        <span class="mb-2 block text-sm font-medium text-slate-800">Channel</span>
        <div class="flex gap-4">
          <label class="flex items-center gap-2 text-sm text-slate-700">
            <input v-model="form.channel" type="radio" value="whatsapp" class="text-indigo-600" /> WhatsApp
          </label>
          <label class="flex items-center gap-2 text-sm text-slate-700">
            <input v-model="form.channel" type="radio" value="sms" class="text-indigo-600" /> SMS
          </label>
        </div>
        <p v-if="fieldError('channel')" class="mt-1 text-xs text-red-600">{{ fieldError('channel') }}</p>
      </div>

      <!-- Lead time -->
      <div>
        <label class="mb-1 block text-sm font-medium text-slate-800">Lead time (hours before appointment)</label>
        <input
          v-model="form.lead_hours"
          type="number"
          min="1"
          max="168"
          class="w-40 rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none"
        />
        <p v-if="fieldError('lead_hours')" class="mt-1 text-xs text-red-600">{{ fieldError('lead_hours') }}</p>
      </div>

      <!-- WhatsApp connection -->
      <fieldset v-if="form.channel === 'whatsapp'" class="space-y-3 rounded-xl border border-slate-200 p-4">
        <legend class="px-1 text-sm font-semibold text-slate-700">
          WhatsApp connection
          <span v-if="hasCredentials.whatsapp" class="ml-2 text-xs font-normal text-emerald-600">connected</span>
        </legend>
        <div>
          <label class="mb-1 block text-xs font-medium text-slate-600">Phone Number ID</label>
          <input v-model="form.credentials.phone_number_id" type="text" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
        </div>
        <div>
          <label class="mb-1 block text-xs font-medium text-slate-600">Access Token</label>
          <input
            v-model="form.credentials.access_token"
            type="password"
            :placeholder="hasCredentials.whatsapp ? '•••••••• (leave blank to keep)' : ''"
            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
          />
        </div>
        <div>
          <label class="mb-1 block text-xs font-medium text-slate-600">Template name</label>
          <input v-model="form.credentials.template_name" type="text" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
        </div>
      </fieldset>

      <!-- SMS connection -->
      <fieldset v-else class="space-y-3 rounded-xl border border-slate-200 p-4">
        <legend class="px-1 text-sm font-semibold text-slate-700">
          SMS connection
          <span v-if="hasCredentials.sms" class="ml-2 text-xs font-normal text-emerald-600">connected</span>
        </legend>
        <div>
          <label class="mb-1 block text-xs font-medium text-slate-600">Provider</label>
          <input v-model="form.credentials.provider" type="text" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
        </div>
        <div>
          <label class="mb-1 block text-xs font-medium text-slate-600">From number</label>
          <input v-model="form.credentials.from" type="text" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
        </div>
        <div>
          <label class="mb-1 block text-xs font-medium text-slate-600">API key</label>
          <input
            v-model="form.credentials.api_key"
            type="password"
            :placeholder="hasCredentials.sms ? '•••••••• (leave blank to keep)' : ''"
            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
          />
        </div>
      </fieldset>

      <p v-if="formMessage" class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{{ formMessage }}</p>
      <p v-if="savedOk" class="rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700">Settings saved.</p>

      <div class="flex justify-end">
        <button
          type="submit"
          :disabled="saving"
          class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 disabled:opacity-60"
        >
          {{ saving ? 'Saving…' : 'Save settings' }}
        </button>
      </div>
    </form>

    <p v-else class="text-sm text-slate-500">Loading…</p>
  </div>
</template>
```

- [ ] **Step 2: Register the route**

Modify `frontend/src/router/index.js`. Add a child route inside the authenticated `children` array, after the `customers` entry:

```javascript
        {
          path: 'settings',
          name: 'settings',
          component: () => import('@/views/SettingsView.vue'),
          meta: { requiresAuth: true },
        },
```

- [ ] **Step 3: Add the nav item**

Modify `frontend/src/layouts/DashboardLayout.vue`. Append this object to the end of the `nav` array (after the `Customers` entry):

```javascript
  {
    name: 'Settings',
    to: '/settings',
    d: 'M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.281z M15 12a3 3 0 11-6 0 3 3 0 016 0z',
  },
```

- [ ] **Step 4: Build to verify it compiles**

Run: `cd frontend && npm run build`
Expected: build completes with no errors.

- [ ] **Step 5: Commit**

```bash
cd frontend && git add src/views/SettingsView.vue src/router/index.js src/layouts/DashboardLayout.vue
git commit -m "feat: reminder settings UI (Settings view + nav)"
```

---

## Task 7: End-to-end verification

**Files:** none (verification only).

This task follows the project norm: verify independently, never trust reports blindly. Verify backend suite, the reminder pipeline against the DB + log, and the settings UI in a browser.

- [ ] **Step 1: Full backend suite is green**

Run: `cd backend && php artisan test`
Expected: all tests pass (existing suite + new Reminders tests).

- [ ] **Step 2: Seed a due appointment and run the pipeline against the log driver**

Enable reminders for an org and point an appointment into the lead window, then run the command and flush the queue:

```bash
cd backend
php artisan tinker --execute="
\$org = App\Models\Organization::first();
App\Models\ReminderSetting::updateOrCreate(
  ['organization_id' => \$org->id],
  ['enabled' => true, 'channel' => 'whatsapp', 'lead_hours' => 24]
);
\$appt = App\Models\Appointment::where('organization_id', \$org->id)->first();
\$appt->update([
  'status' => 'confirmed',
  'reminder_sent_at' => null,
  'booking_date' => now()->addHours(3)->toDateString(),
  'start_time' => now()->addHours(3)->format('H:i:s'),
]);
\$appt->customer->update(['phone' => '+15550100']);
echo 'seeded appt '.\$appt->id.PHP_EOL;
"
php artisan reminders:send
php artisan queue:work --stop-when-empty
```

Expected: `queue:work` reports one `SendAppointmentReminder` job processed (DONE).

- [ ] **Step 3: Confirm the reminder was logged and the flag was set**

```bash
cd backend
grep "\[reminder\]" storage/logs/laravel.log | tail -1
php artisan tinker --execute="echo App\Models\Appointment::whereNotNull('reminder_sent_at')->count().' reminded'.PHP_EOL;"
```

Expected: a `[reminder] to=+15550100 :: Reminder: … See you soon!` log line, and the reminded count ≥ 1.

- [ ] **Step 4: Confirm dedupe — a second run sends nothing**

```bash
cd backend && php artisan reminders:send && php artisan queue:work --stop-when-empty
```

Expected: no job processed (the appointment's `reminder_sent_at` is already set).

- [ ] **Step 5: Browser check the settings page**

Start the servers (backend `php artisan serve`, frontend `npm run dev`), log in, open `/settings`. Verify: toggle enable, switch channel WhatsApp↔SMS (connection subform swaps), enter a lead time + credentials, Save → "Settings saved." Reload → enable/channel/lead persist; secret fields render blank with the "leave blank to keep" placeholder and a "connected" badge. Confirm via DB that the secret is stored encrypted:

```bash
cd backend && php artisan tinker --execute="\$s = App\Models\ReminderSetting::first(); echo (\$s->credentials['access_token'] ?? '(none)').PHP_EOL;"
```

Expected: prints the decrypted token via the model (proving encrypted-at-rest + decrypt-on-read). Stop the dev servers when done.

- [ ] **Step 6: Final confirmation**

Report: backend suite green (count), pipeline log line seen, dedupe confirmed, UI save/persist verified. No temp servers left running.

---

## Self-Review (completed during authoring)

- **Spec coverage:** data model (Task 1) ✓; channel abstraction + log driver (Task 2) ✓; reminder engine with timezone window + atomic-claim dedupe + status/phone filters (Task 3) ✓; fixed message template (Task 3, `buildMessage`) ✓; command + hourly scheduler (Task 4) ✓; tenant-scoped settings API with no secret leakage + blank-preserves-secret (Task 5) ✓; Settings UI + nav + honest log-mode note (Task 6) ✓; testing incl. e2e (Tasks 1–7) ✓.
- **Placeholder scan:** no TBD/TODO; every code step shows full code.
- **Type consistency:** `ReminderChannelManager::for(string): ReminderChannel`, `SendAppointmentReminder(public int $appointmentId)`, `AppointmentReminderService::dispatchDue(): void`, settings payload keys (`enabled`, `channel`, `lead_hours`, `has_credentials.{whatsapp,sms}`) used identically across backend + frontend.
