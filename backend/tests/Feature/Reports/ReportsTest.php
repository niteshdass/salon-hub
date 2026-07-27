<?php

namespace Tests\Feature\Reports;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Review;
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
        $this->assertSame(0, $points[0]['bookings']);
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

        // A > 182-day span buckets by month.
        $res = $this->withToken($this->token($owner))->getJson('/api/reports?from=2026-01-01&to=2026-12-31');

        $res->assertJsonPath('data.revenue.granularity', 'month');
        $points = collect($res->json('data.revenue.points'));
        $this->assertSame(12, $points->count()); // Jan..Dec, zero-filled.
        $this->assertSame(100.0, (float) $points->firstWhere('period', '2026-01')['earned']);
        $this->assertSame(0.0, (float) $points->firstWhere('period', '2026-02')['earned']);
        $this->assertSame(200.0, (float) $points->firstWhere('period', '2026-03')['earned']);
    }

    public function test_revenue_series_is_weekly_for_mid_range(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');

        // ISO weeks in 2026: Jan 5 (Mon) & Jan 6 (Tue) are in 2026-W02; Jan 15 is in 2026-W03.
        $this->makeAppointment($org, ['date' => '2026-01-05', 'price' => 40, 'status' => 'completed']);
        $this->makeAppointment($org, ['date' => '2026-01-06', 'price' => 60, 'status' => 'completed']);
        $this->makeAppointment($org, ['date' => '2026-01-15', 'price' => 30, 'status' => 'completed']);

        // A ~120-day span (>31, <=182) buckets by week.
        $res = $this->withToken($this->token($owner))->getJson('/api/reports?from=2026-01-01&to=2026-04-30');

        $res->assertJsonPath('data.revenue.granularity', 'week');
        $points = collect($res->json('data.revenue.points'));
        // Bookings land in the correct ISO-week buckets, summed.
        $this->assertSame(100.0, (float) $points->firstWhere('period', '2026-W02')['earned']);
        $this->assertSame(2, $points->firstWhere('period', '2026-W02')['bookings']);
        $this->assertSame(30.0, (float) $points->firstWhere('period', '2026-W03')['earned']);
        // Weeks with no bookings are zero-filled.
        $this->assertSame(0.0, (float) $points->firstWhere('period', '2026-W01')['earned']);
    }

    public function test_revenue_series_granularity_band_boundaries(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');
        $token = $this->token($owner);

        // 31-day span -> day; 32 -> week.
        $this->withToken($token)->getJson('/api/reports?from=2026-01-01&to=2026-02-01')
            ->assertJsonPath('data.revenue.granularity', 'day');
        $this->withToken($token)->getJson('/api/reports?from=2026-01-01&to=2026-02-02')
            ->assertJsonPath('data.revenue.granularity', 'week');

        // 182-day span -> week; 183 -> month.
        $this->withToken($token)->getJson('/api/reports?from=2026-01-01&to=2026-07-02')
            ->assertJsonPath('data.revenue.granularity', 'week');
        $this->withToken($token)->getJson('/api/reports?from=2026-01-01&to=2026-07-03')
            ->assertJsonPath('data.revenue.granularity', 'month');
    }

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
        // Ranked by earned desc: Alice (80) before Bob (40).
        $this->assertSame('Alice Wong', $res->json('data.staff.0.name'));
        $this->assertSame(80.0, (float) $rows['Alice Wong']['earned']);
        $this->assertSame(2, $rows['Alice Wong']['bookings']);
        $this->assertSame(5.0, (float) $rows['Alice Wong']['rating']['average']);
        $this->assertSame(1, $rows['Alice Wong']['rating']['count']);
        $this->assertNull($rows['Bob Stone']['rating']['average']);
    }

    public function test_staff_rating_excludes_hidden_reviews(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');
        $branch = $this->makeBranch($org);
        $service = $this->makeService($org, 'Cut', 40);
        $alice = $this->makeStaff($org, 'Alice Wong');

        $a1 = $this->makeAppointment($org, ['date' => '2026-07-05', 'price' => 40, 'status' => 'completed', 'branch' => $branch, 'service' => $service, 'staff' => $alice]);
        $a2 = $this->makeAppointment($org, ['date' => '2026-07-06', 'price' => 40, 'status' => 'completed', 'branch' => $branch, 'service' => $service, 'staff' => $alice]);

        // A published 5-star and a hidden 1-star: only the published one counts.
        Review::create([
            'organization_id' => $org->id,
            'appointment_id' => $a1->id,
            'staff_id' => $alice->id,
            'rating' => 5,
            'comment' => 'Great',
            'reviewer_name' => 'Casey Customer',
            'status' => 'published',
        ]);
        Review::create([
            'organization_id' => $org->id,
            'appointment_id' => $a2->id,
            'staff_id' => $alice->id,
            'rating' => 1,
            'comment' => 'Hidden',
            'reviewer_name' => 'Casey Customer',
            'status' => 'hidden',
        ]);

        $res = $this->withToken($this->token($owner))->getJson('/api/reports?from=2026-07-01&to=2026-07-31');

        $rows = collect($res->json('data.staff'))->keyBy('name');
        $this->assertSame(5.0, (float) $rows['Alice Wong']['rating']['average']); // hidden 1-star excluded
        $this->assertSame(1, $rows['Alice Wong']['rating']['count']);
    }

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

    public function test_reports_show_only_the_callers_organization_data(): void
    {
        // Foreign org: rich data in the SAME date range that must NEVER appear
        // in the caller's report — only the tenant scope keeps it out.
        $foreign = $this->makeOrg('foreign');
        $fBranch = $this->makeBranch($foreign);
        $fService = $this->makeService($foreign, 'Foreign Cut', 99);
        $fStaff = $this->makeStaff($foreign, 'Foreign Stylist');
        $fAppt = $this->makeAppointment($foreign, ['date' => '2026-07-10', 'price' => 99, 'status' => 'completed', 'branch' => $fBranch, 'service' => $fService, 'staff' => $fStaff]);
        Review::create([
            'organization_id' => $foreign->id,
            'appointment_id' => $fAppt->id,
            'staff_id' => $fStaff->id,
            'rating' => 5,
            'comment' => 'Foreign review',
            'reviewer_name' => 'Foreign Customer',
            'status' => 'published',
        ]);

        // Caller org: its own modest data.
        $mine = $this->makeOrg('mine');
        $owner = $this->makeUser($mine, 'owner');
        $myBranch = $this->makeBranch($mine);
        $myService = $this->makeService($mine, 'Local Cut', 25);
        $myStaff = $this->makeStaff($mine, 'Local Stylist');
        $this->makeAppointment($mine, ['date' => '2026-07-12', 'price' => 25, 'status' => 'completed', 'branch' => $myBranch, 'service' => $myService, 'staff' => $myStaff]);

        // ONE authenticated request. The codebase tests endpoint isolation with a
        // single request (see TenantScopingTest::test_customers_endpoint_returns_only_authed_org):
        // two authenticated requests in one test hit Laravel's auth-guard user cache
        // (the guard memoises the resolved user across HTTP calls that share the test's
        // app instance) — an artifact that never occurs in production, where each request
        // boots fresh. Foreign data present + never requested is the real isolation proof.
        $res = $this->withToken($this->token($owner))->getJson('/api/reports?from=2026-07-01&to=2026-07-31');
        $res->assertOk();

        // Caller sees ONLY its own numbers — not 99 (foreign), not 124 (foreign + own).
        $this->assertSame(25.0, (float) $res->json('data.summary.earned'));
        $this->assertSame(1, (int) $res->json('data.summary.bookings'));
        $res->assertJsonPath('data.bookings.by_status.completed', 1);

        // Foreign service/staff never leak into the caller's tables.
        $serviceNames = collect($res->json('data.top_services'))->pluck('name')->all();
        $this->assertSame(['Local Cut'], $serviceNames);
        $this->assertNotContains('Foreign Cut', $serviceNames);

        $staffNames = collect($res->json('data.staff'))->pluck('name')->all();
        $this->assertSame(['Local Stylist'], $staffNames);
        $this->assertNotContains('Foreign Stylist', $staffNames);
    }
}
