<?php

namespace Tests\Feature\Crud;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * appointments.{branch_id,customer_id,staff_id,service_id} are all
 * `cascadeOnDelete` (2026_07_22_100010_create_appointments_table.php:16-20)
 * and payments cascade from appointments
 * (2026_07_24_185701_create_payments_table.php:18). No model in this app uses
 * SoftDeletes, so before these guards the most ordinary admin action there is
 * — tidying up an old service, or removing a stylist who left — silently
 * destroyed completed bookings and the revenue history the Reports page reads.
 *
 * These tests pin both halves of each guard: the refusal when dependents
 * exist, and that an ordinary delete with no dependents still works.
 */
class DeleteDependencyGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Organization, 1: User, 2: string}
     */
    private function makeOrgWithOwner(string $slug): array
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

        return [$org, $owner, $owner->createToken('api')->plainTextToken];
    }

    private function makeBranch(Organization $org, string $name = 'Main'): Branch
    {
        return Branch::create([
            'organization_id' => $org->id,
            'name' => $name,
            'address' => '1 High Street',
            'city' => 'Dhaka',
            'country' => 'BD',
        ]);
    }

    private function makeService(Organization $org, string $name = 'Haircut'): Service
    {
        return Service::create([
            'organization_id' => $org->id,
            'name' => $name,
            'duration' => 30,
            'price' => 20,
        ]);
    }

    private function makeStaff(Organization $org, string $email): User
    {
        return User::create([
            'organization_id' => $org->id,
            'name' => 'Stylist '.$email,
            'email' => $email,
            'password' => 'secret1234',
            'role' => 'staff',
            'status' => 'active',
        ]);
    }

    private function makeAppointment(
        Organization $org,
        Branch $branch,
        Customer $customer,
        User $staff,
        Service $service,
    ): Appointment {
        return Appointment::create([
            'organization_id' => $org->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'staff_id' => $staff->id,
            'service_id' => $service->id,
            'booking_date' => '2026-09-14',
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'price' => 20,
            'status' => 'completed',
        ]);
    }

    // ---------------------------------------------------------------- service

    public function test_deleting_a_service_with_appointments_is_refused_and_keeps_the_history(): void
    {
        [$org, , $token] = $this->makeOrgWithOwner('svc-guard');
        $branch = $this->makeBranch($org);
        $service = $this->makeService($org);
        $staff = $this->makeStaff($org, 'stylist@svc-guard.test');
        $customer = Customer::create(['organization_id' => $org->id, 'name' => 'Repeat Client']);
        $appointment = $this->makeAppointment($org, $branch, $customer, $staff, $service);

        $response = $this->withToken($token)->deleteJson("/api/services/{$service->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('services', ['id' => $service->id]);
        $this->assertDatabaseHas('appointments', ['id' => $appointment->id]);
    }

    public function test_a_service_with_no_appointments_still_deletes(): void
    {
        [$org, , $token] = $this->makeOrgWithOwner('svc-clean');
        $service = $this->makeService($org, 'Never Booked');

        $this->withToken($token)->deleteJson("/api/services/{$service->id}")->assertNoContent();

        $this->assertDatabaseMissing('services', ['id' => $service->id]);
    }

    // --------------------------------------------------------------- customer

    public function test_deleting_a_customer_with_appointments_is_refused_and_keeps_the_history(): void
    {
        [$org, , $token] = $this->makeOrgWithOwner('cust-guard');
        $branch = $this->makeBranch($org);
        $service = $this->makeService($org);
        $staff = $this->makeStaff($org, 'stylist@cust-guard.test');
        $customer = Customer::create(['organization_id' => $org->id, 'name' => 'Has History']);
        $appointment = $this->makeAppointment($org, $branch, $customer, $staff, $service);

        $response = $this->withToken($token)->deleteJson("/api/customers/{$customer->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
        $this->assertDatabaseHas('appointments', ['id' => $appointment->id]);
    }

    public function test_a_customer_with_no_appointments_still_deletes(): void
    {
        [$org, , $token] = $this->makeOrgWithOwner('cust-clean');
        $customer = Customer::create(['organization_id' => $org->id, 'name' => 'Walked In Once']);

        $this->withToken($token)->deleteJson("/api/customers/{$customer->id}")->assertNoContent();

        $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
    }

    // ----------------------------------------------------------------- branch

    public function test_deleting_a_branch_with_appointments_is_refused_and_keeps_the_history(): void
    {
        [$org, , $token] = $this->makeOrgWithOwner('branch-guard');
        $branch = $this->makeBranch($org);
        $service = $this->makeService($org);
        $staff = $this->makeStaff($org, 'stylist@branch-guard.test');
        $customer = Customer::create(['organization_id' => $org->id, 'name' => 'Booked Here']);
        $appointment = $this->makeAppointment($org, $branch, $customer, $staff, $service);

        $response = $this->withToken($token)->deleteJson("/api/branches/{$branch->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('branches', ['id' => $branch->id]);
        $this->assertDatabaseHas('appointments', ['id' => $appointment->id]);
    }

    public function test_a_branch_with_no_appointments_still_deletes(): void
    {
        [$org, , $token] = $this->makeOrgWithOwner('branch-clean');
        $branch = $this->makeBranch($org, 'Second Chair');

        $this->withToken($token)->deleteJson("/api/branches/{$branch->id}")->assertNoContent();

        $this->assertDatabaseMissing('branches', ['id' => $branch->id]);
    }

    // ------------------------------------------------------------------ staff

    public function test_deleting_a_staff_member_with_appointments_is_refused_and_keeps_the_history(): void
    {
        [$org, , $token] = $this->makeOrgWithOwner('staff-guard');
        $branch = $this->makeBranch($org);
        $service = $this->makeService($org);
        $staff = $this->makeStaff($org, 'stylist@staff-guard.test');
        $customer = Customer::create(['organization_id' => $org->id, 'name' => 'Their Client']);
        $appointment = $this->makeAppointment($org, $branch, $customer, $staff, $service);

        $response = $this->withToken($token)->deleteJson("/api/staff/{$staff->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('users', ['id' => $staff->id]);
        $this->assertDatabaseHas('appointments', ['id' => $appointment->id]);
    }

    public function test_a_staff_member_with_no_appointments_still_deletes(): void
    {
        [$org, , $token] = $this->makeOrgWithOwner('staff-clean');
        $staff = $this->makeStaff($org, 'newhire@staff-clean.test');

        $this->withToken($token)->deleteJson("/api/staff/{$staff->id}")->assertNoContent();

        $this->assertDatabaseMissing('users', ['id' => $staff->id]);
    }

    /**
     * The guard must not leak the existence of another tenant's bookings: a
     * cross-tenant id is a 404 from route-model binding, never a 422.
     */
    public function test_the_guard_does_not_change_cross_tenant_404_behaviour(): void
    {
        [, , $tokenA] = $this->makeOrgWithOwner('tenant-a');
        [$orgB] = $this->makeOrgWithOwner('tenant-b');

        $serviceB = $this->makeService($orgB, 'B Only');

        $this->withToken($tokenA)->deleteJson("/api/services/{$serviceB->id}")->assertNotFound();
        $this->assertDatabaseHas('services', ['id' => $serviceB->id]);
    }
}
