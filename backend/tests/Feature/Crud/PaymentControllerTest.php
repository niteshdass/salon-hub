<?php

namespace Tests\Feature\Crud;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * An org with an owner, a staff member, and one $40 appointment.
     *
     * @return array<string, mixed>
     */
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
        $owner = User::create([
            'organization_id' => $org->id, 'name' => 'Owner',
            'email' => "owner@{$slug}.test", 'password' => 'secret1234',
            'role' => 'owner', 'status' => 'active',
        ]);
        $staff = User::create([
            'organization_id' => $org->id, 'name' => 'Stylist',
            'email' => "stylist@{$slug}.test", 'password' => 'secret1234',
            'role' => 'staff', 'status' => 'active',
        ]);
        $branch = Branch::create(['organization_id' => $org->id, 'name' => 'Main']);
        $service = Service::create([
            'organization_id' => $org->id, 'name' => 'Haircut',
            'duration' => 30, 'price' => 40, 'status' => 'active',
        ]);
        $customer = Customer::create([
            'organization_id' => $org->id, 'name' => 'Casey', 'phone' => '+15550100',
        ]);
        $appointment = Appointment::create([
            'organization_id' => $org->id, 'branch_id' => $branch->id,
            'customer_id' => $customer->id, 'staff_id' => $staff->id,
            'service_id' => $service->id,
            'booking_date' => Carbon::tomorrow()->toDateString(),
            'start_time' => '10:00:00', 'end_time' => '10:30:00',
            'price' => 40, 'status' => 'completed',
        ]);

        return [
            'org' => $org, 'owner' => $owner, 'staff' => $staff,
            'ownerToken' => $owner->createToken('api')->plainTextToken,
            'staffToken' => $staff->createToken('api')->plainTextToken,
            'appointment' => $appointment,
        ];
    }

    /**
     * Bearer-authenticate as a role. Sanctum's RequestGuard memoizes the
     * resolved user per test app, so a second token in the same test is
     * ignored unless the guards are forgotten first.
     */
    private function as(array $ctx, string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    public function test_records_a_payment_against_an_appointment(): void
    {
        $ctx = $this->scaffold('recpay');

        $response = $this->withToken($ctx['staffToken'])->postJson(
            "/api/appointments/{$ctx['appointment']->id}/payments",
            ['amount' => 25, 'method' => 'cash', 'reference' => 'till-1'],
        );

        $response->assertCreated();
        $response->assertJsonPath('data.amount', '25.00');
        $response->assertJsonPath('data.method', 'cash');
        $response->assertJsonPath('data.reference', 'till-1');
        $response->assertJsonPath('data.recorded_by', 'Stylist');

        $this->assertDatabaseHas('payments', [
            'organization_id' => $ctx['org']->id,
            'appointment_id' => $ctx['appointment']->id,
            'amount' => 25,
            'method' => 'cash',
            'recorded_by' => $ctx['staff']->id,
        ]);
    }

    public function test_lists_payments_for_an_appointment_newest_first(): void
    {
        $ctx = $this->scaffold('listpay');
        $url = "/api/appointments/{$ctx['appointment']->id}/payments";

        $this->withToken($ctx['staffToken'])->postJson($url, ['amount' => 10, 'method' => 'cash'])->assertCreated();
        $this->withToken($ctx['staffToken'])->postJson($url, ['amount' => 30, 'method' => 'card'])->assertCreated();

        $response = $this->withToken($ctx['staffToken'])->getJson($url);

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.amount', '30.00');
        $response->assertJsonPath('data.1.amount', '10.00');
    }

    public function test_amount_and_method_are_validated(): void
    {
        $ctx = $this->scaffold('valpay');
        $url = "/api/appointments/{$ctx['appointment']->id}/payments";

        $this->withToken($ctx['staffToken'])->postJson($url, ['amount' => 0, 'method' => 'cash'])
            ->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->withToken($ctx['staffToken'])->postJson($url, ['amount' => 10, 'method' => 'bitcoin'])
            ->assertStatus(422)->assertJsonValidationErrors('method');
    }

    public function test_owner_deletes_a_payment_but_staff_cannot(): void
    {
        $ctx = $this->scaffold('delpay');
        $url = "/api/appointments/{$ctx['appointment']->id}/payments";
        $paymentId = $this->withToken($ctx['staffToken'])
            ->postJson($url, ['amount' => 15, 'method' => 'cash'])->json('data.id');

        $this->as($ctx, $ctx['staffToken'])->deleteJson("{$url}/{$paymentId}")->assertForbidden();
        $this->as($ctx, $ctx['ownerToken'])->deleteJson("{$url}/{$paymentId}")->assertNoContent();

        $this->assertDatabaseMissing('payments', ['id' => $paymentId]);
    }

    public function test_cannot_record_a_payment_against_another_tenants_appointment(): void
    {
        $mine = $this->scaffold('mine-pay');
        $theirs = $this->scaffold('their-pay');

        // My token, their appointment id: the tenant scope hides it → 404.
        $this->withToken($mine['staffToken'])->postJson(
            "/api/appointments/{$theirs['appointment']->id}/payments",
            ['amount' => 5, 'method' => 'cash'],
        )->assertNotFound();
    }

    public function test_a_payment_bound_to_another_appointment_is_not_deletable_here(): void
    {
        $ctx = $this->scaffold('mixpay');
        $other = Appointment::create([
            'organization_id' => $ctx['org']->id,
            'branch_id' => $ctx['appointment']->branch_id,
            'customer_id' => $ctx['appointment']->customer_id,
            'staff_id' => $ctx['staff']->id,
            'service_id' => $ctx['appointment']->service_id,
            'booking_date' => Carbon::tomorrow()->toDateString(),
            'start_time' => '11:00:00', 'end_time' => '11:30:00',
            'price' => 40, 'status' => 'completed',
        ]);
        $payment = Payment::create([
            'organization_id' => $ctx['org']->id,
            'appointment_id' => $other->id,
            'amount' => 20, 'method' => 'cash',
        ]);

        // The payment exists, but not under THIS appointment → 404, not 204.
        $this->withToken($ctx['ownerToken'])->deleteJson(
            "/api/appointments/{$ctx['appointment']->id}/payments/{$payment->id}",
        )->assertNotFound();
    }
}
