<?php

namespace Tests\Feature\Public;

use App\Mail\BookingConfirmationMail;
use App\Mail\NewBookingMail;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Service;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicBookingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build an ACTIVE org with a branch, one active 30-min service, and a
     * staff member (with a profile) assigned to that service.
     *
     * Data is seeded with explicit organization_id: no tenant is bound during
     * seeding, so the auto-scope / auto-fill stay dormant.
     *
     * @param  array<int>  $workingDays  ISO weekdays (1=Mon..7=Sun) the staff works.
     * @return array<string, mixed>
     */
    private function scaffold(string $slug, array $workingDays = [1, 2, 3, 4, 5, 6, 7]): array
    {
        $org = Organization::create([
            'uuid' => (string) Str::uuid(),
            'name' => ucfirst($slug),
            'slug' => $slug,
            'email' => "owner@{$slug}.test",
            'subscription_plan' => 'free',
            'status' => 'active',
        ]);

        $branch = Branch::create([
            'organization_id' => $org->id,
            'name' => 'Main',
            'city' => 'Metropolis',
            'address' => '1 High Street',
            'phone' => '+1 555 0000',
        ]);

        $service = Service::create([
            'organization_id' => $org->id,
            'name' => 'Haircut',
            'duration' => 30,
            'price' => 25,
            'status' => 'active',
        ]);

        $staff = User::create([
            'organization_id' => $org->id,
            'name' => 'Stylist',
            'email' => "stylist@{$slug}.test",
            'password' => 'secret1234',
            'role' => 'staff',
            'status' => 'active',
        ]);

        StaffProfile::create([
            'user_id' => $staff->id,
            'designation' => 'Senior Stylist',
            'working_days_json' => $workingDays,
            'working_hours_json' => ['start' => '09:00', 'end' => '17:00'],
        ]);

        $staff->services()->attach($service->id);

        return compact('org', 'branch', 'service', 'staff');
    }

    /** A fixed, deterministic future Monday (ISO weekday 1). */
    private function nextMonday(): string
    {
        return Carbon::parse('next monday')->format('Y-m-d');
    }

    public function test_unknown_org_slug_returns_404(): void
    {
        $this->scaffold('alpha');

        $this->getJson('/api/public/nope/services')->assertNotFound();
    }

    public function test_services_endpoint_returns_only_active_and_tenant_scoped_services(): void
    {
        $ctx = $this->scaffold('bravo');

        Service::create([
            'organization_id' => $ctx['org']->id,
            'name' => 'Retired Service',
            'duration' => 60,
            'price' => 50,
            'status' => 'inactive',
        ]);

        // A different org's active service must never appear.
        $other = $this->scaffold('bravo-other');

        $response = $this->getJson('/api/public/bravo/services');
        $response->assertOk();

        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Haircut'));
        $this->assertFalse($names->contains('Retired Service'));
        $this->assertCount(1, $response->json('data'));
        $this->assertNotEquals($other['service']->id, $response->json('data.0.id'));
    }

    public function test_staff_for_service_returns_the_assigned_staff(): void
    {
        $ctx = $this->scaffold('charlie');

        $response = $this->getJson("/api/public/charlie/services/{$ctx['service']->id}/staff");
        $response->assertOk();

        $response->assertJsonPath('data.0.id', $ctx['staff']->id);
        $response->assertJsonPath('data.0.name', 'Stylist');
        $response->assertJsonPath('data.0.designation', 'Senior Stylist');
        $this->assertCount(1, $response->json('data'));
    }

    public function test_slots_returns_grid_and_excludes_conflicting_slot(): void
    {
        $ctx = $this->scaffold('delta');
        $date = $this->nextMonday();

        $customer = Customer::create([
            'organization_id' => $ctx['org']->id,
            'name' => 'Booked Client',
            'phone' => '555-0001',
        ]);

        // Existing pending appointment 10:00-10:30 for the staff on the date.
        Appointment::create([
            'organization_id' => $ctx['org']->id,
            'branch_id' => $ctx['branch']->id,
            'customer_id' => $customer->id,
            'staff_id' => $ctx['staff']->id,
            'service_id' => $ctx['service']->id,
            'booking_date' => $date,
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'status' => 'pending',
        ]);

        $response = $this->getJson(
            "/api/public/delta/slots?service_id={$ctx['service']->id}&staff_id={$ctx['staff']->id}&date={$date}"
        );
        $response->assertOk();
        $response->assertJsonPath('data.date', $date);

        $slots = $response->json('data.slots');
        $this->assertContains('09:00', $slots);
        $this->assertContains('10:30', $slots);
        $this->assertNotContains('10:00', $slots);
        // 30-min service, 09:00-17:00 window -> last start is 16:30.
        $this->assertContains('16:30', $slots);
        $this->assertNotContains('16:31', $slots);
    }

    public function test_branch_opening_hours_narrow_the_slot_window(): void
    {
        $ctx = $this->scaffold('india');
        $date = $this->nextMonday();

        // Staff works 09:00-17:00, but the branch only opens 10:00-12:00 on Monday.
        $ctx['branch']->update(['opening_hours_json' => ['mon' => ['10:00', '12:00']]]);

        $response = $this->getJson(
            "/api/public/india/slots?service_id={$ctx['service']->id}&staff_id={$ctx['staff']->id}&date={$date}"
        );
        $response->assertOk();

        $slots = $response->json('data.slots');
        $this->assertSame(['10:00', '10:30', '11:00', '11:30'], $slots);
    }

    public function test_branch_closed_weekday_has_no_slots(): void
    {
        $ctx = $this->scaffold('juliet');
        $date = $this->nextMonday();

        // Branch explicitly closed on Monday (null), even though the staff works.
        $ctx['branch']->update(['opening_hours_json' => ['mon' => null, 'tue' => ['09:00', '17:00']]]);

        $response = $this->getJson(
            "/api/public/juliet/slots?service_id={$ctx['service']->id}&staff_id={$ctx['staff']->id}&date={$date}"
        );
        $response->assertOk();
        $this->assertSame([], $response->json('data.slots'));
    }

    public function test_booking_outside_branch_hours_is_rejected(): void
    {
        $ctx = $this->scaffold('kilo');
        $date = $this->nextMonday();
        $ctx['branch']->update(['opening_hours_json' => ['mon' => ['10:00', '12:00']]]);

        // 09:00 is within staff hours but before the branch opens.
        $response = $this->postJson('/api/public/kilo/book', [
            'service_id' => $ctx['service']->id,
            'staff_id' => $ctx['staff']->id,
            'date' => $date,
            'start_time' => '09:00',
            'customer' => ['name' => 'Too Early Tom', 'phone' => '555-0000'],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Sorry, that time slot is no longer available.');
        $this->assertDatabaseMissing('customers', ['phone' => '555-0000']);
    }

    public function test_slots_empty_on_a_closed_weekday(): void
    {
        // Staff works only Sundays (ISO 7); the target date is a Monday.
        $ctx = $this->scaffold('echo', workingDays: [7]);
        $date = $this->nextMonday();

        $response = $this->getJson(
            "/api/public/echo/slots?service_id={$ctx['service']->id}&staff_id={$ctx['staff']->id}&date={$date}"
        );
        $response->assertOk();
        $this->assertSame([], $response->json('data.slots'));
    }

    public function test_book_creates_pending_appointment_and_customer_then_rejects_duplicate(): void
    {
        $ctx = $this->scaffold('foxtrot');
        $date = $this->nextMonday();

        $payload = [
            'service_id' => $ctx['service']->id,
            'staff_id' => $ctx['staff']->id,
            'date' => $date,
            'start_time' => '11:00',
            'customer' => ['name' => 'Public Pat', 'phone' => '555-7777'],
        ];

        $response = $this->postJson('/api/public/foxtrot/book', $payload);
        $response->assertCreated();
        $response->assertJsonPath('data.status', 'pending');
        $response->assertJsonPath('data.start_time', '11:00');
        $response->assertJsonPath('data.end_time', '11:30');
        $response->assertJsonPath('data.customer.name', 'Public Pat');

        $this->assertDatabaseHas('appointments', [
            'id' => $response->json('data.id'),
            'organization_id' => $ctx['org']->id,
            'staff_id' => $ctx['staff']->id,
            'start_time' => '11:00:00',
            'end_time' => '11:30:00',
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('customers', [
            'organization_id' => $ctx['org']->id,
            'phone' => '555-7777',
            'name' => 'Public Pat',
        ]);

        // Booking the same slot again -> 422 (no double-booking).
        $duplicate = $this->postJson('/api/public/foxtrot/book', $payload);
        $duplicate->assertStatus(422);
        $duplicate->assertJsonPath('message', 'Sorry, that time slot is no longer available.');
    }

    public function test_booking_queues_customer_and_salon_emails(): void
    {
        Mail::fake();

        $ctx = $this->scaffold('golf');

        $this->postJson('/api/public/golf/book', [
            'service_id' => $ctx['service']->id,
            'staff_id' => $ctx['staff']->id,
            'date' => $this->nextMonday(),
            'start_time' => '11:00',
            'customer' => ['name' => 'Emailed Emma', 'phone' => '555-1212', 'email' => 'emma@example.test'],
        ])->assertCreated();

        Mail::assertQueued(
            BookingConfirmationMail::class,
            fn (BookingConfirmationMail $mail) => $mail->hasTo('emma@example.test'),
        );
        Mail::assertQueued(
            NewBookingMail::class,
            fn (NewBookingMail $mail) => $mail->hasTo($ctx['org']->email),
        );
    }

    public function test_booking_without_email_only_notifies_the_salon(): void
    {
        Mail::fake();

        $ctx = $this->scaffold('hotel');

        $this->postJson('/api/public/hotel/book', [
            'service_id' => $ctx['service']->id,
            'staff_id' => $ctx['staff']->id,
            'date' => $this->nextMonday(),
            'start_time' => '11:00',
            'customer' => ['name' => 'No Email Ned', 'phone' => '555-3434'],
        ])->assertCreated();

        Mail::assertNotQueued(BookingConfirmationMail::class);
        Mail::assertQueued(NewBookingMail::class);
    }

    /** Book a pending appointment and return the decoded booking payload. */
    private function bookSlot(string $slug, array $ctx, string $start = '11:00'): array
    {
        $response = $this->postJson("/api/public/{$slug}/book", [
            'service_id' => $ctx['service']->id,
            'staff_id' => $ctx['staff']->id,
            'date' => $this->nextMonday(),
            'start_time' => $start,
            'customer' => ['name' => 'Manage Mona', 'phone' => '555-9090'],
        ]);
        $response->assertCreated();

        return $response->json('data');
    }

    public function test_book_returns_a_public_manage_token_that_can_view_the_booking(): void
    {
        $ctx = $this->scaffold('mike');
        $booking = $this->bookSlot('mike', $ctx);

        $this->assertNotEmpty($booking['public_token']);

        $response = $this->getJson("/api/public/mike/manage/{$booking['public_token']}");
        $response->assertOk();
        $response->assertJsonPath('data.id', $booking['id']);
        $response->assertJsonPath('data.start_time', '11:00');
        $response->assertJsonPath('data.status', 'pending');
        $response->assertJsonPath('data.changeable', true);
        $response->assertJsonPath('data.salon.slug', 'mike');
    }

    public function test_manage_unknown_token_returns_404(): void
    {
        $this->scaffold('november');

        $this->getJson('/api/public/november/manage/'.Str::uuid())->assertNotFound();
    }

    public function test_customer_can_reschedule_to_an_open_slot(): void
    {
        $ctx = $this->scaffold('oscar');
        $booking = $this->bookSlot('oscar', $ctx, '11:00');

        $response = $this->postJson("/api/public/oscar/manage/{$booking['public_token']}/reschedule", [
            'date' => $this->nextMonday(),
            'start_time' => '14:00',
        ]);
        $response->assertOk();
        $response->assertJsonPath('data.start_time', '14:00');
        $response->assertJsonPath('data.end_time', '14:30');
        $response->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('appointments', [
            'id' => $booking['id'],
            'start_time' => '14:00:00',
            'end_time' => '14:30:00',
        ]);
    }

    public function test_reschedule_to_the_same_time_is_allowed(): void
    {
        // The appointment must not block its own slot on reschedule.
        $ctx = $this->scaffold('papa');
        $booking = $this->bookSlot('papa', $ctx, '11:00');

        $this->postJson("/api/public/papa/manage/{$booking['public_token']}/reschedule", [
            'date' => $this->nextMonday(),
            'start_time' => '11:00',
        ])->assertOk();
    }

    public function test_reschedule_onto_a_taken_slot_is_rejected(): void
    {
        $ctx = $this->scaffold('quebec');
        $date = $this->nextMonday();
        $booking = $this->bookSlot('quebec', $ctx, '11:00');

        $blocker = Customer::create([
            'organization_id' => $ctx['org']->id,
            'name' => 'Blocker Bob',
            'phone' => '555-2222',
        ]);
        Appointment::create([
            'organization_id' => $ctx['org']->id,
            'branch_id' => $ctx['branch']->id,
            'customer_id' => $blocker->id,
            'staff_id' => $ctx['staff']->id,
            'service_id' => $ctx['service']->id,
            'booking_date' => $date,
            'start_time' => '14:00:00',
            'end_time' => '14:30:00',
            'status' => 'confirmed',
        ]);

        $response = $this->postJson("/api/public/quebec/manage/{$booking['public_token']}/reschedule", [
            'date' => $date,
            'start_time' => '14:00',
        ]);
        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Sorry, that time slot is no longer available.');

        // Original slot is untouched.
        $this->assertDatabaseHas('appointments', [
            'id' => $booking['id'],
            'start_time' => '11:00:00',
        ]);
    }

    public function test_customer_can_cancel_a_booking_and_free_the_slot(): void
    {
        $ctx = $this->scaffold('romeo');
        $booking = $this->bookSlot('romeo', $ctx, '11:00');

        $response = $this->postJson("/api/public/romeo/manage/{$booking['public_token']}/cancel");
        $response->assertOk();
        $response->assertJsonPath('data.status', 'cancelled');
        $response->assertJsonPath('data.changeable', false);

        $this->assertDatabaseHas('appointments', [
            'id' => $booking['id'],
            'status' => 'cancelled',
        ]);

        // The freed 11:00 slot is bookable again.
        $slots = $this->getJson(
            "/api/public/romeo/slots?service_id={$ctx['service']->id}&staff_id={$ctx['staff']->id}&date={$this->nextMonday()}"
        )->json('data.slots');
        $this->assertContains('11:00', $slots);
    }

    public function test_a_cancelled_booking_cannot_be_rescheduled_or_cancelled_again(): void
    {
        $ctx = $this->scaffold('sierra');
        $booking = $this->bookSlot('sierra', $ctx, '11:00');

        $this->postJson("/api/public/sierra/manage/{$booking['public_token']}/cancel")->assertOk();

        $this->postJson("/api/public/sierra/manage/{$booking['public_token']}/reschedule", [
            'date' => $this->nextMonday(),
            'start_time' => '14:00',
        ])->assertStatus(422)->assertJsonPath('message', 'This booking can no longer be changed.');

        $this->postJson("/api/public/sierra/manage/{$booking['public_token']}/cancel")
            ->assertStatus(422)->assertJsonPath('message', 'This booking can no longer be changed.');
    }

    public function test_manage_token_is_isolated_between_organizations(): void
    {
        $a = $this->scaffold('tango-a');
        $b = $this->scaffold('tango-b');
        $booking = $this->bookSlot('tango-a', $a);

        // A valid token cannot be viewed or mutated through another org's slug.
        $this->getJson("/api/public/tango-b/manage/{$booking['public_token']}")->assertNotFound();
        $this->postJson("/api/public/tango-b/manage/{$booking['public_token']}/cancel")->assertNotFound();
    }

    public function test_tenant_isolation_between_orgs_services(): void
    {
        $a = $this->scaffold('org-a');
        $b = $this->scaffold('org-b');

        $response = $this->getJson('/api/public/org-b/services');
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($b['service']->id));
        $this->assertFalse($ids->contains($a['service']->id));
        $this->assertCount(1, $ids);
    }
}
