<?php

namespace Tests\Feature\Crud;

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
 * The calendar loads a whole month/week at once, so the appointment index
 * accepts a from/to range in addition to the single-day `date` filter.
 */
class AppointmentRangeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function scaffold(): array
    {
        $org = Organization::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Alpha',
            'slug' => 'alpha',
            'email' => 'owner@alpha.test',
            'subscription_plan' => 'free',
            'status' => 'active',
        ]);

        $owner = User::create([
            'organization_id' => $org->id,
            'name' => 'Alpha owner',
            'email' => 'owner@alpha.test',
            'password' => 'secret1234',
            'role' => 'owner',
            'status' => 'active',
        ]);

        $staff = User::create([
            'organization_id' => $org->id,
            'name' => 'Stylist',
            'email' => 'stylist@alpha.test',
            'password' => 'secret1234',
            'role' => 'staff',
            'status' => 'active',
        ]);

        return [
            'org' => $org,
            'staff' => $staff,
            'branch' => Branch::create(['organization_id' => $org->id, 'name' => 'Main']),
            'service' => Service::create([
                'organization_id' => $org->id,
                'name' => 'Haircut',
                'duration' => 30,
                'price' => 25,
            ]),
            'customer' => Customer::create([
                'organization_id' => $org->id,
                'name' => 'Client',
                'phone' => '+1 555 0100',
            ]),
            'token' => $owner->createToken('api')->plainTextToken,
        ];
    }

    /**
     * @param  array<string, mixed>  $s
     */
    private function bookOn(array $s, string $date, string $start = '10:00:00'): Appointment
    {
        return Appointment::create([
            'organization_id' => $s['org']->id,
            'branch_id' => $s['branch']->id,
            'customer_id' => $s['customer']->id,
            'staff_id' => $s['staff']->id,
            'service_id' => $s['service']->id,
            'booking_date' => $date,
            'start_time' => $start,
            'end_time' => '10:30:00',
            'status' => 'confirmed',
        ]);
    }

    public function test_index_returns_only_appointments_inside_an_inclusive_date_range(): void
    {
        $s = $this->scaffold();
        $base = Carbon::parse('2026-08-10');

        $this->bookOn($s, $base->copy()->subDay()->toDateString());   // before
        $first = $this->bookOn($s, $base->toDateString());            // on `from`
        $last = $this->bookOn($s, $base->copy()->addDays(6)->toDateString()); // on `to`
        $this->bookOn($s, $base->copy()->addDays(7)->toDateString()); // after

        $response = $this->withToken($s['token'])->getJson('/api/appointments?'.http_build_query([
            'from' => $base->toDateString(),
            'to' => $base->copy()->addDays(6)->toDateString(),
        ]));

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.id', $first->id);
        $response->assertJsonPath('data.1.id', $last->id);
    }

    public function test_range_filter_combines_with_the_staff_filter(): void
    {
        $s = $this->scaffold();

        $other = User::create([
            'organization_id' => $s['org']->id,
            'name' => 'Other Stylist',
            'email' => 'stylist2@alpha.test',
            'password' => 'secret1234',
            'role' => 'staff',
            'status' => 'active',
        ]);

        $mine = $this->bookOn($s, '2026-08-11');
        $this->bookOn($s, '2026-09-05'); // same staff, outside the range
        Appointment::create([
            'organization_id' => $s['org']->id,
            'branch_id' => $s['branch']->id,
            'customer_id' => $s['customer']->id,
            'staff_id' => $other->id,
            'service_id' => $s['service']->id,
            'booking_date' => '2026-08-12',
            'start_time' => '11:00:00',
            'end_time' => '11:30:00',
            'status' => 'confirmed',
        ]);

        $response = $this->withToken($s['token'])->getJson('/api/appointments?'.http_build_query([
            'from' => '2026-08-01',
            'to' => '2026-08-31',
            'staff_id' => $s['staff']->id,
        ]));

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $mine->id);
    }

    public function test_an_open_ended_range_is_accepted(): void
    {
        $s = $this->scaffold();

        $this->bookOn($s, '2026-08-01');
        $later = $this->bookOn($s, '2026-08-20');

        $response = $this->withToken($s['token'])
            ->getJson('/api/appointments?from=2026-08-15');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $later->id);
    }
}
