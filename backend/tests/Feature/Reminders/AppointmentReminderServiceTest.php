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

    public function test_appointment_exactly_at_now_is_excluded(): void
    {
        Queue::fake();
        $frozen = Carbon::parse('2026-08-01 10:00:00', 'UTC');
        $this->travelTo($frozen);
        $org = $this->makeOrg('boundnow', enabled: true, lead: 24);
        $appt = $this->makeAppointment($org, $frozen->copy());

        app(AppointmentReminderService::class)->dispatchDue();

        Queue::assertNothingPushed();
        $this->assertNull($appt->fresh()->reminder_sent_at);
        $this->travelBack();
    }

    public function test_appointment_exactly_at_window_end_is_included(): void
    {
        Queue::fake();
        $frozen = Carbon::parse('2026-08-01 10:00:00', 'UTC');
        $this->travelTo($frozen);
        $org = $this->makeOrg('boundend', enabled: true, lead: 24);
        $appt = $this->makeAppointment($org, $frozen->copy()->addHours(24));

        app(AppointmentReminderService::class)->dispatchDue();

        Queue::assertPushed(SendAppointmentReminder::class, fn ($job) => $job->appointmentId === $appt->id);
        $this->assertNotNull($appt->fresh()->reminder_sent_at);
        $this->travelBack();
    }

    public function test_window_is_computed_in_the_org_timezone_not_utc(): void
    {
        Queue::fake();
        // Freeze now at 2026-08-01 12:00 UTC. Org is +05:30 (no DST).
        // Appointment local wall time 2026-08-02 15:30 IST = 2026-08-02 10:00 UTC,
        // i.e. 22h away -> inside the 24h window. If the window math wrongly
        // parsed the stored time as UTC it would be 2026-08-02 15:30 UTC -> past
        // the window end -> wrongly skipped. So this only passes with real tz math.
        $frozen = Carbon::parse('2026-08-01 12:00:00', 'UTC');
        $this->travelTo($frozen);
        $org = $this->makeOrg('kolkata', enabled: true, lead: 24, tz: 'Asia/Kolkata');
        $startsLocal = Carbon::parse('2026-08-02 15:30:00', 'Asia/Kolkata');
        $appt = $this->makeAppointment($org, $startsLocal);

        app(AppointmentReminderService::class)->dispatchDue();

        Queue::assertPushed(SendAppointmentReminder::class, fn ($job) => $job->appointmentId === $appt->id);
        $this->travelBack();
    }

    public function test_only_enabled_orgs_appointments_are_reminded_when_two_orgs_share_the_table(): void
    {
        Queue::fake();
        $enabled = $this->makeOrg('with-reminders', enabled: true, lead: 24);
        $disabled = $this->makeOrg('no-reminders', enabled: false, lead: 24);
        $apptEnabled = $this->makeAppointment($enabled, Carbon::now()->addHours(2));
        $apptDisabled = $this->makeAppointment($disabled, Carbon::now()->addHours(2));

        app(AppointmentReminderService::class)->dispatchDue();

        Queue::assertPushed(SendAppointmentReminder::class, 1);
        Queue::assertPushed(SendAppointmentReminder::class, fn ($job) => $job->appointmentId === $apptEnabled->id);
        $this->assertNotNull($apptEnabled->fresh()->reminder_sent_at);
        $this->assertNull($apptDisabled->fresh()->reminder_sent_at);
    }
}
