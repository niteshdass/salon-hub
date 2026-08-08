<?php

namespace Tests\Feature\Booking;

use App\Models\Branch;
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
 * The slot engine must drop candidate start times that fall inside a staff
 * member's one-off time-off, on top of the recurring weekly schedule.
 */
class StaffTimeOffSlotTest extends TestCase
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

    private function slots(string $slug, array $ctx, string $date): array
    {
        return $this->getJson(
            "/api/public/{$slug}/slots?service_ids[]={$ctx['service']->id}&staff_id={$ctx['staff']->id}&date={$date}"
        )->json('data.slots');
    }

    public function test_a_full_day_time_off_leaves_no_slots(): void
    {
        $ctx = $this->scaffold('alpha');
        $date = $this->nextMonday();

        StaffTimeOff::create([
            'organization_id' => $ctx['org']->id,
            'user_id' => $ctx['staff']->id,
            'start_at' => $date.' 00:00:00',
            'end_at' => $date.' 23:59:59',
            'reason' => 'Vacation',
        ]);

        $this->assertSame([], $this->slots('alpha', $ctx, $date));
    }

    public function test_a_partial_time_off_blocks_only_the_overlapping_slots(): void
    {
        $ctx = $this->scaffold('bravo');
        $date = $this->nextMonday();

        // Off 12:00–13:00. A 30-min service.
        StaffTimeOff::create([
            'organization_id' => $ctx['org']->id,
            'user_id' => $ctx['staff']->id,
            'start_at' => $date.' 12:00:00',
            'end_at' => $date.' 13:00:00',
            'reason' => 'Lunch',
        ]);

        $slots = $this->slots('bravo', $ctx, $date);

        // 11:30 ends exactly at 12:00 (adjacent) — allowed.
        $this->assertContains('11:30', $slots);
        // 12:00 and 12:30 windows overlap the break — blocked.
        $this->assertNotContains('12:00', $slots);
        $this->assertNotContains('12:30', $slots);
        // 13:00 starts exactly when the break ends — allowed.
        $this->assertContains('13:00', $slots);
    }

    public function test_time_off_on_another_day_does_not_affect_the_date(): void
    {
        $ctx = $this->scaffold('charlie');
        $date = $this->nextMonday();
        $otherDay = Carbon::parse($date)->addDay()->format('Y-m-d');

        StaffTimeOff::create([
            'organization_id' => $ctx['org']->id,
            'user_id' => $ctx['staff']->id,
            'start_at' => $otherDay.' 00:00:00',
            'end_at' => $otherDay.' 23:59:59',
        ]);

        $this->assertContains('09:00', $this->slots('charlie', $ctx, $date));
    }

    public function test_another_staff_members_time_off_does_not_block_this_one(): void
    {
        $ctx = $this->scaffold('delta');
        $date = $this->nextMonday();

        $other = User::create([
            'organization_id' => $ctx['org']->id,
            'name' => 'Other Stylist',
            'email' => 'other@delta.test',
            'password' => 'secret1234',
            'role' => 'staff',
            'status' => 'active',
        ]);

        StaffTimeOff::create([
            'organization_id' => $ctx['org']->id,
            'user_id' => $other->id,
            'start_at' => $date.' 00:00:00',
            'end_at' => $date.' 23:59:59',
        ]);

        $this->assertContains('09:00', $this->slots('delta', $ctx, $date));
    }
}
