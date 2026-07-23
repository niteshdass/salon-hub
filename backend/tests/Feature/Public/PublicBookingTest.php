<?php

namespace Tests\Feature\Public;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Service;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
