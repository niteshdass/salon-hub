<?php

namespace Tests\Feature\Booking;

use App\Models\Branch;
use App\Models\BranchClosure;
use App\Models\Organization;
use App\Models\Service;
use App\Models\StaffProfile;
use App\Models\StaffTimeOff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The public booking + reschedule endpoints re-check the requested time against
 * the slot engine, so a time inside a staff time-off or a branch closure is
 * refused just like a taken slot — no separate guard in the controller.
 */
class BlockedBookingTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function scaffold(string $slug): array
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
            'working_days_json' => [1, 2, 3, 4, 5, 6, 7],
            'working_hours_json' => ['start' => '09:00', 'end' => '17:00'],
        ]);

        $staff->services()->attach($service->id);

        return compact('org', 'branch', 'service', 'staff');
    }

    private function nextMonday(): string
    {
        return Carbon::parse('next monday')->format('Y-m-d');
    }

    public function test_booking_a_slot_inside_staff_time_off_is_rejected(): void
    {
        $ctx = $this->scaffold('alpha');
        $date = $this->nextMonday();

        StaffTimeOff::create([
            'organization_id' => $ctx['org']->id,
            'user_id' => $ctx['staff']->id,
            'start_at' => $date.' 11:00:00',
            'end_at' => $date.' 12:00:00',
        ]);

        $response = $this->postJson('/api/public/alpha/book', [
            'service_id' => $ctx['service']->id,
            'staff_id' => $ctx['staff']->id,
            'date' => $date,
            'start_time' => '11:00',
            'customer' => ['name' => 'Blocked Bob', 'phone' => '555-4141'],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('customers', ['phone' => '555-4141']);
    }

    public function test_booking_on_a_branch_closure_date_is_rejected(): void
    {
        $ctx = $this->scaffold('bravo');
        $date = $this->nextMonday();

        BranchClosure::create([
            'organization_id' => $ctx['org']->id,
            'branch_id' => $ctx['branch']->id,
            'start_date' => $date,
            'end_date' => $date,
            'reason' => 'Holiday',
        ]);

        $response = $this->postJson('/api/public/bravo/book', [
            'service_id' => $ctx['service']->id,
            'staff_id' => $ctx['staff']->id,
            'date' => $date,
            'start_time' => '11:00',
            'customer' => ['name' => 'Holiday Hank', 'phone' => '555-4242'],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('customers', ['phone' => '555-4242']);
    }

    public function test_rescheduling_onto_staff_time_off_is_rejected(): void
    {
        $ctx = $this->scaffold('charlie');
        $date = $this->nextMonday();

        // Book an allowed 11:00 slot first.
        $booking = $this->postJson('/api/public/charlie/book', [
            'service_id' => $ctx['service']->id,
            'staff_id' => $ctx['staff']->id,
            'date' => $date,
            'start_time' => '11:00',
            'customer' => ['name' => 'Move Mia', 'phone' => '555-4343'],
        ]);
        $booking->assertCreated();
        $token = $booking->json('data.public_token');

        // The stylist then blocks 14:00–14:30.
        StaffTimeOff::create([
            'organization_id' => $ctx['org']->id,
            'user_id' => $ctx['staff']->id,
            'start_at' => $date.' 14:00:00',
            'end_at' => $date.' 14:30:00',
        ]);

        $response = $this->postJson("/api/public/charlie/manage/{$token}/reschedule", [
            'date' => $date,
            'start_time' => '14:00',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('appointments', [
            'start_time' => '11:00:00',
        ]);
    }
}
