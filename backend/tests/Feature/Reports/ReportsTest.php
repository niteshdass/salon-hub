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
        $this->assertEquals(29, $from->diffInDays($to));
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

    public function test_malformed_date_is_rejected_cleanly(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');

        $this->withToken($this->token($owner))
            ->getJson('/api/reports?from=not-a-date')
            ->assertStatus(422);
    }

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
}
