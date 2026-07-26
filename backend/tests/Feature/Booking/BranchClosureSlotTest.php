<?php

namespace Tests\Feature\Booking;

use App\Models\Branch;
use App\Models\BranchClosure;
use App\Models\Organization;
use App\Models\Service;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A branch closure (holiday, renovation) removes every slot for that branch on
 * the covered dates. A closure with a NULL branch closes the whole salon.
 */
class BranchClosureSlotTest extends TestCase
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
            "/api/public/{$slug}/slots?service_id={$ctx['service']->id}&staff_id={$ctx['staff']->id}&date={$date}&branch_id={$ctx['branch']->id}"
        )->json('data.slots');
    }

    public function test_a_branch_closure_leaves_no_slots(): void
    {
        $ctx = $this->scaffold('alpha');
        $date = $this->nextMonday();

        BranchClosure::create([
            'organization_id' => $ctx['org']->id,
            'branch_id' => $ctx['branch']->id,
            'start_date' => $date,
            'end_date' => $date,
            'reason' => 'Public holiday',
        ]);

        $this->assertSame([], $this->slots('alpha', $ctx, $date));
    }

    public function test_a_multi_day_closure_covers_a_date_inside_the_range(): void
    {
        $ctx = $this->scaffold('bravo');
        $date = $this->nextMonday();

        BranchClosure::create([
            'organization_id' => $ctx['org']->id,
            'branch_id' => $ctx['branch']->id,
            'start_date' => Carbon::parse($date)->subDay()->format('Y-m-d'),
            'end_date' => Carbon::parse($date)->addDay()->format('Y-m-d'),
            'reason' => 'Renovation',
        ]);

        $this->assertSame([], $this->slots('bravo', $ctx, $date));
    }

    public function test_an_org_wide_closure_with_null_branch_closes_the_branch(): void
    {
        $ctx = $this->scaffold('charlie');
        $date = $this->nextMonday();

        BranchClosure::create([
            'organization_id' => $ctx['org']->id,
            'branch_id' => null,
            'start_date' => $date,
            'end_date' => $date,
            'reason' => 'Company holiday',
        ]);

        $this->assertSame([], $this->slots('charlie', $ctx, $date));
    }

    public function test_a_closure_for_another_branch_does_not_affect_this_one(): void
    {
        $ctx = $this->scaffold('delta');
        $date = $this->nextMonday();

        $otherBranch = Branch::create([
            'organization_id' => $ctx['org']->id,
            'name' => 'Uptown',
            'city' => 'Metropolis',
            'address' => '2 Broad Street',
            'phone' => '+1 555 0001',
        ]);

        BranchClosure::create([
            'organization_id' => $ctx['org']->id,
            'branch_id' => $otherBranch->id,
            'start_date' => $date,
            'end_date' => $date,
        ]);

        $this->assertContains('09:00', $this->slots('delta', $ctx, $date));
    }

    public function test_a_closure_on_another_date_does_not_affect_the_date(): void
    {
        $ctx = $this->scaffold('echo');
        $date = $this->nextMonday();

        BranchClosure::create([
            'organization_id' => $ctx['org']->id,
            'branch_id' => $ctx['branch']->id,
            'start_date' => Carbon::parse($date)->addWeek()->format('Y-m-d'),
            'end_date' => Carbon::parse($date)->addWeek()->format('Y-m-d'),
        ]);

        $this->assertContains('09:00', $this->slots('echo', $ctx, $date));
    }
}
