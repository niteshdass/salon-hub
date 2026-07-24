<?php

namespace Tests\Feature\Dashboard;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The dashboard is one request: today's numbers, the org totals and the
 * next few appointments. The SPA used to assemble this from four list
 * endpoints and count the rows client-side.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function scaffold(string $slug = 'alpha', float $price = 25): array
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
            'name' => 'Owner',
            'email' => "owner@{$slug}.test",
            'password' => 'secret1234',
            'role' => 'owner',
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

        return [
            'org' => $org,
            'owner' => $owner,
            'staff' => $staff,
            'branch' => Branch::create(['organization_id' => $org->id, 'name' => 'Main']),
            'service' => Service::create([
                'organization_id' => $org->id,
                'name' => 'Haircut',
                'duration' => 30,
                'price' => $price,
            ]),
            'customer' => Customer::create([
                'organization_id' => $org->id,
                'name' => 'Client',
                'phone' => '+1 555 0100',
            ]),
            'ownerToken' => $owner->createToken('api')->plainTextToken,
            'staffToken' => $staff->createToken('api')->plainTextToken,
        ];
    }

    /**
     * @param  array<string, mixed>  $s
     */
    private function book(array $s, string $date, string $start, string $status = 'confirmed', ?User $staff = null): Appointment
    {
        return Appointment::create([
            'organization_id' => $s['org']->id,
            'branch_id' => $s['branch']->id,
            'customer_id' => $s['customer']->id,
            'staff_id' => ($staff ?? $s['staff'])->id,
            'service_id' => $s['service']->id,
            'booking_date' => $date,
            'start_time' => $start,
            'end_time' => $start,
            'status' => $status,
        ]);
    }

    private function actingAsRole(array $s, string $role): static
    {
        // Sanctum memoizes the resolved user for the whole test app.
        $this->app['auth']->forgetGuards();

        return $this->withToken($s["{$role}Token"]);
    }

    public function test_it_reports_todays_bookings_and_their_status_breakdown(): void
    {
        Carbon::setTestNow('2026-08-10 12:00:00');
        $s = $this->scaffold();

        $this->book($s, '2026-08-10', '09:00:00', 'completed');
        $this->book($s, '2026-08-10', '14:00:00', 'confirmed');
        $this->book($s, '2026-08-10', '16:00:00', 'cancelled');
        $this->book($s, '2026-08-11', '10:00:00', 'confirmed'); // tomorrow

        $response = $this->actingAsRole($s, 'owner')->getJson('/api/dashboard');

        $response->assertOk();
        $response->assertJsonPath('today.date', '2026-08-10');
        // Cancelled bookings still happened, so they are counted and shown
        // separately rather than quietly dropped.
        $response->assertJsonPath('today.bookings', 3);
        $response->assertJsonPath('today.by_status.completed', 1);
        $response->assertJsonPath('today.by_status.confirmed', 1);
        $response->assertJsonPath('today.by_status.cancelled', 1);
        $response->assertJsonPath('today.by_status.pending', 0);
        $response->assertJsonPath('today.by_status.no_show', 0);
    }

    public function test_todays_revenue_counts_only_completed_bookings(): void
    {
        Carbon::setTestNow('2026-08-10 12:00:00');
        $s = $this->scaffold(price: 30);

        $this->book($s, '2026-08-10', '09:00:00', 'completed');
        $this->book($s, '2026-08-10', '10:00:00', 'completed');
        $this->book($s, '2026-08-10', '14:00:00', 'confirmed'); // not earned yet
        $this->book($s, '2026-08-09', '14:00:00', 'completed'); // yesterday

        $response = $this->actingAsRole($s, 'owner')->getJson('/api/dashboard');

        $response->assertOk();
        $response->assertJsonPath('today.revenue', 60);
    }

    public function test_it_reports_the_organization_totals(): void
    {
        $s = $this->scaffold();
        Branch::create(['organization_id' => $s['org']->id, 'name' => 'Second']);
        Customer::create(['organization_id' => $s['org']->id, 'name' => 'Another', 'phone' => '+1 555 0101']);

        $response = $this->actingAsRole($s, 'owner')->getJson('/api/dashboard');

        $response->assertOk();
        $response->assertJsonPath('totals.branches', 2);
        $response->assertJsonPath('totals.services', 1);
        $response->assertJsonPath('totals.customers', 2);
        $response->assertJsonPath('totals.staff', 2); // owner + stylist
    }

    public function test_upcoming_skips_the_past_and_cancelled_bookings(): void
    {
        Carbon::setTestNow('2026-08-10 12:00:00');
        $s = $this->scaffold();

        $this->book($s, '2026-08-10', '09:00:00');            // already happened
        $this->book($s, '2026-08-10', '15:00:00', 'cancelled'); // called off
        $later = $this->book($s, '2026-08-10', '16:00:00');
        $tomorrow = $this->book($s, '2026-08-11', '09:00:00');

        $response = $this->actingAsRole($s, 'owner')->getJson('/api/dashboard');

        $response->assertOk();
        $response->assertJsonCount(2, 'upcoming');
        $response->assertJsonPath('upcoming.0.id', $later->id);
        $response->assertJsonPath('upcoming.1.id', $tomorrow->id);
        // Rendered as cards, so the names have to come along.
        $response->assertJsonPath('upcoming.0.customer.name', 'Client');
        $response->assertJsonPath('upcoming.0.service.name', 'Haircut');
    }

    public function test_upcoming_is_capped(): void
    {
        Carbon::setTestNow('2026-08-10 12:00:00');
        $s = $this->scaffold();

        foreach (range(1, 8) as $day) {
            $this->book($s, Carbon::parse('2026-08-10')->addDays($day)->toDateString(), '10:00:00');
        }

        $response = $this->actingAsRole($s, 'owner')->getJson('/api/dashboard');

        $response->assertOk();
        $response->assertJsonCount(5, 'upcoming');
    }

    public function test_a_staff_member_only_sees_their_own_schedule(): void
    {
        Carbon::setTestNow('2026-08-10 12:00:00');
        $s = $this->scaffold();

        $mine = $this->book($s, '2026-08-10', '16:00:00');
        $this->book($s, '2026-08-10', '17:00:00', 'confirmed', $s['owner']); // someone else's

        $response = $this->actingAsRole($s, 'staff')->getJson('/api/dashboard');

        $response->assertOk();
        $response->assertJsonPath('today.bookings', 1);
        $response->assertJsonCount(1, 'upcoming');
        $response->assertJsonPath('upcoming.0.id', $mine->id);
        // Revenue is the owner's business, not a stylist's.
        $response->assertJsonMissingPath('today.revenue');
    }

    public function test_another_tenants_data_is_invisible(): void
    {
        Carbon::setTestNow('2026-08-10 12:00:00');
        $alpha = $this->scaffold('alpha');
        $beta = $this->scaffold('beta');

        $this->book($beta, '2026-08-10', '16:00:00');

        $response = $this->actingAsRole($alpha, 'owner')->getJson('/api/dashboard');

        $response->assertOk();
        $response->assertJsonPath('today.bookings', 0);
        $response->assertJsonCount(0, 'upcoming');
        $response->assertJsonPath('totals.customers', 1);
    }

    public function test_a_guest_gets_nothing(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }
}
