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

class AppointmentCrudTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build an organization with an owner (+ token) and a ready-to-book
     * branch, service (30 min), staff member and customer.
     *
     * @return array<string, mixed>
     */
    private function scaffold(string $slug, int $duration = 30): array
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
            'name' => "{$slug} owner",
            'email' => "owner@{$slug}.test",
            'password' => 'secret1234',
            'role' => 'owner',
            'status' => 'active',
        ]);

        $branch = Branch::create([
            'organization_id' => $org->id,
            'name' => 'Main',
        ]);

        $service = Service::create([
            'organization_id' => $org->id,
            'name' => 'Haircut',
            'duration' => $duration,
            'price' => 25,
        ]);

        $staff = User::create([
            'organization_id' => $org->id,
            'name' => 'Stylist',
            'email' => "stylist@{$slug}.test",
            'password' => 'secret1234',
            'role' => 'staff',
            'status' => 'active',
        ]);

        $customer = Customer::create([
            'organization_id' => $org->id,
            'name' => 'Existing Client',
            'phone' => '+1 555 0100',
        ]);

        return [
            'org' => $org,
            'owner' => $owner,
            'token' => $owner->createToken('api')->plainTextToken,
            'branch' => $branch,
            'service' => $service,
            'staff' => $staff,
            'customer' => $customer,
        ];
    }

    private function tomorrow(): string
    {
        return Carbon::tomorrow()->format('Y-m-d');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function bookingPayload(array $ctx, array $overrides = []): array
    {
        return array_merge([
            'branch_id' => $ctx['branch']->id,
            'service_ids' => [$ctx['service']->id],
            'staff_id' => $ctx['staff']->id,
            'customer_id' => $ctx['customer']->id,
            'booking_date' => $this->tomorrow(),
            'start_time' => '10:00',
        ], $overrides);
    }

    public function test_create_appointment_with_existing_customer_derives_end_time(): void
    {
        $ctx = $this->scaffold('alpha');

        $response = $this->withToken($ctx['token'])->postJson('/api/appointments', $this->bookingPayload($ctx));

        $response->assertCreated();
        $response->assertJsonPath('data.start_time', '10:00');
        $response->assertJsonPath('data.end_time', '10:30');
        $response->assertJsonPath('data.status', 'pending');
        $response->assertJsonPath('data.customer.id', $ctx['customer']->id);
        $response->assertJsonPath('data.services.0.duration', 30);
        $response->assertJsonPath('data.staff.id', $ctx['staff']->id);
        $response->assertJsonPath('data.branch.id', $ctx['branch']->id);

        $this->assertDatabaseHas('appointments', [
            'id' => $response->json('data.id'),
            'organization_id' => $ctx['org']->id,
            'staff_id' => $ctx['staff']->id,
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
        ]);
    }

    public function test_create_appointment_with_inline_new_customer(): void
    {
        $ctx = $this->scaffold('beta');

        $payload = $this->bookingPayload($ctx, [
            'customer_id' => null,
            'new_customer' => ['name' => 'Walk In Wanda', 'phone' => '+1 555 0199'],
        ]);
        unset($payload['customer_id']);

        $response = $this->withToken($ctx['token'])->postJson('/api/appointments', $payload);

        $response->assertCreated();
        $response->assertJsonPath('data.customer.name', 'Walk In Wanda');

        $this->assertDatabaseHas('customers', [
            'organization_id' => $ctx['org']->id,
            'name' => 'Walk In Wanda',
            'phone' => '+1 555 0199',
        ]);
    }

    public function test_create_snapshots_service_price_onto_the_appointment(): void
    {
        $ctx = $this->scaffold('priced');

        $response = $this->withToken($ctx['token'])->postJson('/api/appointments', $this->bookingPayload($ctx));

        $response->assertCreated();
        $response->assertJsonPath('data.price', '25.00');

        $this->assertDatabaseHas('appointments', [
            'id' => $response->json('data.id'),
            'price' => 25,
        ]);
    }

    public function test_price_snapshot_survives_a_later_service_price_change(): void
    {
        $ctx = $this->scaffold('frozen');

        $id = $this->withToken($ctx['token'])
            ->postJson('/api/appointments', $this->bookingPayload($ctx))
            ->json('data.id');

        // Raising the menu price must not rewrite what an existing booking owes.
        $ctx['service']->update(['price' => 99]);

        $this->assertDatabaseHas('appointments', ['id' => $id, 'price' => 25]);
    }

    public function test_overlapping_booking_for_same_staff_conflicts(): void
    {
        $ctx = $this->scaffold('gamma');

        // 10:00-10:30 booked.
        $this->withToken($ctx['token'])->postJson('/api/appointments', $this->bookingPayload($ctx))
            ->assertCreated();

        // 10:15 overlaps -> 422.
        $conflict = $this->withToken($ctx['token'])->postJson(
            '/api/appointments',
            $this->bookingPayload($ctx, ['start_time' => '10:15'])
        );
        $conflict->assertStatus(422);
        $conflict->assertJsonPath('message', 'This staff member is already booked for an overlapping time slot.');

        // 10:30 is back-to-back, not overlapping -> 201.
        $adjacent = $this->withToken($ctx['token'])->postJson(
            '/api/appointments',
            $this->bookingPayload($ctx, ['start_time' => '10:30'])
        );
        $adjacent->assertCreated();
        $adjacent->assertJsonPath('data.end_time', '11:00');
    }

    public function test_same_slot_for_different_staff_does_not_conflict(): void
    {
        $ctx = $this->scaffold('delta');

        $staff2 = User::create([
            'organization_id' => $ctx['org']->id,
            'name' => 'Second Stylist',
            'email' => 'stylist2@delta.test',
            'password' => 'secret1234',
            'role' => 'staff',
            'status' => 'active',
        ]);

        $this->withToken($ctx['token'])->postJson('/api/appointments', $this->bookingPayload($ctx))
            ->assertCreated();

        $other = $this->withToken($ctx['token'])->postJson(
            '/api/appointments',
            $this->bookingPayload($ctx, ['staff_id' => $staff2->id])
        );
        $other->assertCreated();
    }

    public function test_cancelled_appointment_does_not_block_the_slot(): void
    {
        $ctx = $this->scaffold('epsilon');

        // A cancelled appointment occupying 10:00-10:30.
        Appointment::create([
            'organization_id' => $ctx['org']->id,
            'branch_id' => $ctx['branch']->id,
            'customer_id' => $ctx['customer']->id,
            'staff_id' => $ctx['staff']->id,
            'service_id' => $ctx['service']->id,
            'booking_date' => $this->tomorrow(),
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'status' => 'cancelled',
        ]);

        $response = $this->withToken($ctx['token'])->postJson('/api/appointments', $this->bookingPayload($ctx));
        $response->assertCreated();
    }

    public function test_index_date_filter_and_tenant_isolation(): void
    {
        $ctx = $this->scaffold('zeta');

        // One booking tomorrow, one the day after.
        $this->withToken($ctx['token'])->postJson('/api/appointments', $this->bookingPayload($ctx))
            ->assertCreated();
        $dayAfter = Carbon::tomorrow()->addDay()->format('Y-m-d');
        $this->withToken($ctx['token'])->postJson(
            '/api/appointments',
            $this->bookingPayload($ctx, ['booking_date' => $dayAfter, 'start_time' => '12:00'])
        )->assertCreated();

        // Filter by tomorrow -> only one.
        $filtered = $this->withToken($ctx['token'])->getJson('/api/appointments?date='.$this->tomorrow());
        $filtered->assertOk();
        $this->assertCount(1, $filtered->json('data'));
        $this->assertSame('10:00', $filtered->json('data.0.start_time'));

        // Org B cannot see org A's appointments. (Reset the guard so the
        // Sanctum RequestGuard re-resolves the user for the new token —
        // it memoizes within a single process, which only matters here in
        // the test where several requests share one app instance.)
        $ctxB = $this->scaffold('eta');
        $this->app['auth']->forgetGuards();
        $listB = $this->withToken($ctxB['token'])->getJson('/api/appointments');
        $listB->assertOk();
        $this->assertCount(0, $listB->json('data'));
    }

    public function test_cross_tenant_ids_fail_validation(): void
    {
        $ctxA = $this->scaffold('theta');
        $ctxB = $this->scaffold('iota');

        $response = $this->withToken($ctxA['token'])->postJson('/api/appointments', [
            'branch_id' => $ctxB['branch']->id,
            'service_ids' => [$ctxB['service']->id],
            'staff_id' => $ctxB['staff']->id,
            'customer_id' => $ctxB['customer']->id,
            'booking_date' => $this->tomorrow(),
            'start_time' => '10:00',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['branch_id', 'service_ids.0', 'staff_id', 'customer_id']);
    }

    public function test_update_reschedule_rechecks_conflict_and_status_change(): void
    {
        $ctx = $this->scaffold('kappa');

        // Booking A at 10:00, Booking B at 11:00.
        $a = $this->withToken($ctx['token'])->postJson('/api/appointments', $this->bookingPayload($ctx));
        $a->assertCreated();
        $b = $this->withToken($ctx['token'])->postJson(
            '/api/appointments',
            $this->bookingPayload($ctx, ['start_time' => '11:00'])
        );
        $b->assertCreated();

        // Rescheduling B onto A's slot -> conflict.
        $conflict = $this->withToken($ctx['token'])->patchJson('/api/appointments/'.$b->json('data.id'), [
            'start_time' => '10:15',
        ]);
        $conflict->assertStatus(422);

        // Status change on A succeeds (own-id excluded from conflict check).
        $status = $this->withToken($ctx['token'])->patchJson('/api/appointments/'.$a->json('data.id'), [
            'status' => 'confirmed',
        ]);
        $status->assertOk();
        $status->assertJsonPath('data.status', 'confirmed');
        $status->assertJsonPath('data.end_time', '10:30');
    }
}
