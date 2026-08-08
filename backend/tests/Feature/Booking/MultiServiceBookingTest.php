<?php

namespace Tests\Feature\Booking;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MultiServiceBookingTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $env;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Acme', 'slug' => 'acme',
            'email' => 'owner@acme.test', 'subscription_plan' => 'free', 'status' => 'active',
        ]);
        $owner = User::create([
            'organization_id' => $org->id, 'name' => 'Owner', 'email' => 'owner@acme.test',
            'password' => 'secret1234', 'role' => 'owner', 'status' => 'active',
        ]);
        $staff = User::create([
            'organization_id' => $org->id, 'name' => 'Stylist', 'email' => 'stylist@acme.test',
            'password' => 'secret1234', 'role' => 'staff', 'status' => 'active',
        ]);

        $this->env = [
            'org' => $org,
            'token' => $owner->createToken('api')->plainTextToken,
            'staff' => $staff,
            'branch' => Branch::create(['organization_id' => $org->id, 'name' => 'Main']),
            'customer' => Customer::create([
                'organization_id' => $org->id, 'name' => 'Casey', 'phone' => '+15550100',
            ]),
            'cut' => Service::create([
                'organization_id' => $org->id, 'name' => 'Haircut',
                'duration' => 30, 'price' => 40, 'status' => 'active',
            ]),
            'dry' => Service::create([
                'organization_id' => $org->id, 'name' => 'Blow Dry',
                'duration' => 20, 'price' => 15, 'status' => 'active',
            ]),
        ];
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'branch_id' => $this->env['branch']->id,
            'customer_id' => $this->env['customer']->id,
            'staff_id' => $this->env['staff']->id,
            'service_ids' => [$this->env['cut']->id, $this->env['dry']->id],
            'booking_date' => '2026-09-01',
            'start_time' => '10:00',
        ], $overrides);
    }

    public function test_a_two_service_booking_sums_price_and_duration(): void
    {
        $response = $this->withToken($this->env['token'])
            ->postJson('/api/appointments', $this->payload())
            ->assertCreated();

        $response->assertJsonPath('data.price', '55.00');
        $response->assertJsonPath('data.end_time', '10:50');
        $response->assertJsonPath('data.duration', 50);
        $response->assertJsonPath('data.services.0.name', 'Haircut');
        $response->assertJsonPath('data.services.1.name', 'Blow Dry');
    }

    public function test_conflict_detection_covers_the_whole_summed_block(): void
    {
        $this->withToken($this->env['token'])
            ->postJson('/api/appointments', $this->payload())
            ->assertCreated();

        // 10:40 falls inside the 10:00–10:50 block only because of the second
        // service; a single-service booking would have ended at 10:30.
        $this->withToken($this->env['token'])
            ->postJson('/api/appointments', $this->payload([
                'start_time' => '10:40',
                'service_ids' => [$this->env['cut']->id],
            ]))
            // AppointmentController::conflictResponse() returns 422 for every
            // other overlap test in this suite (AppointmentCrudTest); this
            // path shares that helper, so it is 422 here too.
            ->assertStatus(422);
    }

    public function test_editing_the_service_set_recomputes_the_total(): void
    {
        $id = $this->withToken($this->env['token'])
            ->postJson('/api/appointments', $this->payload())
            ->json('data.id');

        $this->withToken($this->env['token'])
            ->patchJson("/api/appointments/{$id}", ['service_ids' => [$this->env['dry']->id]])
            ->assertOk()
            ->assertJsonPath('data.price', '15.00')
            ->assertJsonPath('data.end_time', '10:20')
            ->assertJsonCount(1, 'data.services');

        $this->assertDatabaseCount('appointment_services', 1);
    }

    public function test_an_empty_service_list_is_rejected(): void
    {
        $this->withToken($this->env['token'])
            ->postJson('/api/appointments', $this->payload(['service_ids' => []]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('service_ids');
    }

    public function test_a_service_from_another_salon_is_rejected(): void
    {
        $other = Organization::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Rival', 'slug' => 'rival',
            'email' => 'owner@rival.test', 'subscription_plan' => 'free', 'status' => 'active',
        ]);
        $foreign = Service::create([
            'organization_id' => $other->id, 'name' => 'Foreign',
            'duration' => 30, 'price' => 99, 'status' => 'active',
        ]);

        $this->withToken($this->env['token'])
            ->postJson('/api/appointments', $this->payload([
                'service_ids' => [$this->env['cut']->id, $foreign->id],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('service_ids.1');

        $this->assertSame(0, Appointment::query()->count());
    }
}
