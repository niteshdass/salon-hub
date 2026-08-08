<?php

namespace Tests\Feature\Authorization;

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
 * Role matrix (CLAUDE.md):
 *   owner   — everything
 *   manager — appointments, customers, staff, services; read-only branches;
 *             no reminder settings
 *   staff   — read-only catalogue, own schedule only, status-only updates
 */
class RolePermissionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Org with one user per role plus a bookable branch/service/customer.
     *
     * @return array<string, mixed>
     */
    private function scaffold(string $slug = 'alpha'): array
    {
        $org = Organization::create([
            'uuid' => (string) Str::uuid(),
            'name' => ucfirst($slug),
            'slug' => $slug,
            'email' => "owner@{$slug}.test",
            'subscription_plan' => 'free',
            'status' => 'active',
        ]);

        $make = fn (string $role) => User::create([
            'organization_id' => $org->id,
            'name' => "{$slug} {$role}",
            'email' => "{$role}@{$slug}.test",
            'password' => 'secret1234',
            'role' => $role,
            'status' => 'active',
        ]);

        $owner = $make('owner');
        $manager = $make('manager');
        $staff = $make('staff');

        $other = User::create([
            'organization_id' => $org->id,
            'name' => 'Other Stylist',
            'email' => "stylist2@{$slug}.test",
            'password' => 'secret1234',
            'role' => 'staff',
            'status' => 'active',
        ]);

        $branch = Branch::create(['organization_id' => $org->id, 'name' => 'Main']);
        $service = Service::create([
            'organization_id' => $org->id,
            'name' => 'Haircut',
            'duration' => 30,
            'price' => 25,
        ]);
        $customer = Customer::create([
            'organization_id' => $org->id,
            'name' => 'Client',
            'phone' => '+1 555 0100',
        ]);

        return [
            'org' => $org,
            'owner' => $owner,
            'manager' => $manager,
            'staff' => $staff,
            'other' => $other,
            'branch' => $branch,
            'service' => $service,
            'customer' => $customer,
            'ownerToken' => $owner->createToken('api')->plainTextToken,
            'managerToken' => $manager->createToken('api')->plainTextToken,
            'staffToken' => $staff->createToken('api')->plainTextToken,
        ];
    }

    /**
     * Sanctum's RequestGuard memoizes the user it resolved, and the test
     * application is not rebooted between calls — so a second request in
     * the same test would still authenticate as the first role. Dropping
     * the guards forces a fresh resolution per role.
     *
     * @param  array<string, mixed>  $s
     */
    private function actingAsRole(array $s, string $role): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($s["{$role}Token"]);
    }

    /**
     * @param  array<string, mixed>  $s
     */
    private function makeAppointment(array $s, User $staff, string $status = 'pending'): Appointment
    {
        return Appointment::create([
            'organization_id' => $s['org']->id,
            'branch_id' => $s['branch']->id,
            'customer_id' => $s['customer']->id,
            'staff_id' => $staff->id,
            'booking_date' => Carbon::tomorrow()->format('Y-m-d'),
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'status' => $status,
        ]);
    }

    // --- branches: owner-only writes -------------------------------------

    public function test_manager_can_read_branches_but_not_create_one(): void
    {
        $s = $this->scaffold();

        $this->actingAsRole($s, 'manager')->getJson('/api/branches')->assertOk();

        $this->actingAsRole($s, 'manager')
            ->postJson('/api/branches', ['name' => 'Second'])
            ->assertForbidden();
    }

    public function test_staff_cannot_update_or_delete_a_branch(): void
    {
        $s = $this->scaffold();
        $id = $s['branch']->id;

        $this->actingAsRole($s, 'staff')
            ->putJson("/api/branches/{$id}", ['name' => 'Renamed'])
            ->assertForbidden();

        $this->actingAsRole($s, 'staff')
            ->deleteJson("/api/branches/{$id}")
            ->assertForbidden();
    }

    // --- services / categories -------------------------------------------

    public function test_manager_can_create_a_service(): void
    {
        $s = $this->scaffold();

        $this->actingAsRole($s, 'manager')->postJson('/api/services', [
            'name' => 'Beard Trim',
            'duration' => 20,
            'price' => 12,
        ])->assertCreated();
    }

    public function test_staff_can_read_services_but_not_create_one(): void
    {
        $s = $this->scaffold();

        $this->actingAsRole($s, 'staff')->getJson('/api/services')->assertOk();

        $this->actingAsRole($s, 'staff')->postJson('/api/services', [
            'name' => 'Beard Trim',
            'duration' => 20,
            'price' => 12,
        ])->assertForbidden();
    }

    public function test_staff_cannot_create_a_service_category(): void
    {
        $s = $this->scaffold();

        $this->actingAsRole($s, 'staff')
            ->postJson('/api/categories', ['name' => 'Hair'])
            ->assertForbidden();
    }

    // --- staff members ----------------------------------------------------

    public function test_manager_can_create_a_staff_member_but_staff_cannot(): void
    {
        $s = $this->scaffold();

        $this->actingAsRole($s, 'manager')->postJson('/api/staff', [
            'name' => 'New Stylist',
            'email' => 'new@alpha.test',
            'password' => 'secret1234',
            'role' => 'staff',
        ])->assertCreated();

        $this->actingAsRole($s, 'staff')->postJson('/api/staff', [
            'name' => 'Another',
            'email' => 'another@alpha.test',
            'password' => 'secret1234',
            'role' => 'staff',
        ])->assertForbidden();
    }

    // --- customers --------------------------------------------------------

    public function test_staff_can_read_customers_but_not_write_them(): void
    {
        $s = $this->scaffold();

        $this->actingAsRole($s, 'staff')->getJson('/api/customers')->assertOk();

        $this->actingAsRole($s, 'staff')
            ->postJson('/api/customers', ['name' => 'Walk In'])
            ->assertForbidden();

        $this->actingAsRole($s, 'staff')
            ->deleteJson("/api/customers/{$s['customer']->id}")
            ->assertForbidden();
    }

    public function test_manager_can_create_a_customer(): void
    {
        $s = $this->scaffold();

        $this->actingAsRole($s, 'manager')
            ->postJson('/api/customers', ['name' => 'Walk In', 'phone' => '+1 555 0111'])
            ->assertCreated();
    }

    // --- appointments -----------------------------------------------------

    public function test_staff_index_returns_only_their_own_appointments(): void
    {
        $s = $this->scaffold();
        $mine = $this->makeAppointment($s, $s['staff']);
        $this->makeAppointment($s, $s['other']);

        $response = $this->actingAsRole($s, 'staff')->getJson('/api/appointments');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $mine->id);
    }

    public function test_manager_index_returns_all_appointments(): void
    {
        $s = $this->scaffold();
        $this->makeAppointment($s, $s['staff']);
        $this->makeAppointment($s, $s['other']);

        $this->actingAsRole($s, 'manager')
            ->getJson('/api/appointments')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_staff_can_update_the_status_of_their_own_appointment(): void
    {
        $s = $this->scaffold();
        $appointment = $this->makeAppointment($s, $s['staff']);

        $this->actingAsRole($s, 'staff')
            ->putJson("/api/appointments/{$appointment->id}", ['status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_staff_cannot_view_or_update_another_staff_members_appointment(): void
    {
        $s = $this->scaffold();
        $foreign = $this->makeAppointment($s, $s['other']);

        $this->actingAsRole($s, 'staff')
            ->getJson("/api/appointments/{$foreign->id}")
            ->assertForbidden();

        $this->actingAsRole($s, 'staff')
            ->putJson("/api/appointments/{$foreign->id}", ['status' => 'completed'])
            ->assertForbidden();
    }

    public function test_staff_cannot_reschedule_their_own_appointment(): void
    {
        $s = $this->scaffold();
        $appointment = $this->makeAppointment($s, $s['staff']);

        $this->actingAsRole($s, 'staff')
            ->putJson("/api/appointments/{$appointment->id}", [
                'status' => 'confirmed',
                'start_time' => '14:00',
            ])
            ->assertForbidden();
    }

    public function test_staff_cannot_create_or_delete_appointments(): void
    {
        $s = $this->scaffold();
        $appointment = $this->makeAppointment($s, $s['staff']);

        $this->actingAsRole($s, 'staff')->postJson('/api/appointments', [
            'branch_id' => $s['branch']->id,
            'customer_id' => $s['customer']->id,
            'staff_id' => $s['staff']->id,
            'service_id' => $s['service']->id,
            'booking_date' => Carbon::tomorrow()->format('Y-m-d'),
            'start_time' => '12:00',
        ])->assertForbidden();

        $this->actingAsRole($s, 'staff')
            ->deleteJson("/api/appointments/{$appointment->id}")
            ->assertForbidden();
    }

    public function test_manager_can_delete_an_appointment(): void
    {
        $s = $this->scaffold();
        $appointment = $this->makeAppointment($s, $s['other']);

        $this->actingAsRole($s, 'manager')
            ->deleteJson("/api/appointments/{$appointment->id}")
            ->assertNoContent();
    }

    // --- reminder settings: owner only -----------------------------------

    public function test_only_the_owner_can_read_and_update_reminder_settings(): void
    {
        $s = $this->scaffold();

        $this->actingAsRole($s, 'owner')->getJson('/api/settings/reminders')->assertOk();

        $this->actingAsRole($s, 'manager')->getJson('/api/settings/reminders')->assertForbidden();
        $this->actingAsRole($s, 'staff')->getJson('/api/settings/reminders')->assertForbidden();

        $this->actingAsRole($s, 'manager')->putJson('/api/settings/reminders', [
            'enabled' => true,
            'channel' => 'whatsapp',
            'lead_hours' => 24,
        ])->assertForbidden();
    }

    // --- owner keeps full access -----------------------------------------

    public function test_owner_retains_full_access_across_resources(): void
    {
        $s = $this->scaffold();

        $this->actingAsRole($s, 'owner')
            ->postJson('/api/branches', ['name' => 'Second'])
            ->assertStatus(422); // plan limit, not authorization

        $this->actingAsRole($s, 'owner')
            ->postJson('/api/customers', ['name' => 'VIP'])
            ->assertCreated();

        $this->actingAsRole($s, 'owner')
            ->deleteJson("/api/branches/{$s['branch']->id}")
            ->assertNoContent();
    }
}
