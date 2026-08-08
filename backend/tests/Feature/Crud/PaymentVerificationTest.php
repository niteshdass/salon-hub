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

class PaymentVerificationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function scaffold(string $slug): array
    {
        $org = Organization::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Glow', 'slug' => $slug,
            'email' => "owner@{$slug}.test", 'subscription_plan' => 'free', 'status' => 'active',
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
            'organization_id' => $org->id, 'name' => 'Colour',
            'duration' => 60, 'price' => 40, 'status' => 'active',
        ]);
        $customer = Customer::create(['organization_id' => $org->id, 'name' => 'Casey', 'phone' => '+15550100']);
        $appointment = Appointment::create([
            'organization_id' => $org->id, 'branch_id' => $branch->id,
            'customer_id' => $customer->id, 'staff_id' => $staff->id,
            'booking_date' => Carbon::tomorrow()->toDateString(),
            'start_time' => '10:00:00', 'end_time' => '11:00:00',
            'price' => 40, 'status' => 'confirmed',
        ]);

        return [
            'org' => $org, 'appointment' => $appointment,
            'ownerToken' => $owner->createToken('api')->plainTextToken,
            'staffToken' => $staff->createToken('api')->plainTextToken,
        ];
    }

    private function as(array $ctx, string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    public function test_staff_recorded_payments_are_verified_immediately(): void
    {
        $ctx = $this->scaffold('ver-staff');

        $this->withToken($ctx['staffToken'])->postJson(
            "/api/appointments/{$ctx['appointment']->id}/payments",
            ['amount' => 40, 'method' => 'cash'],
        )->assertCreated()->assertJsonPath('data.status', 'verified');
    }

    public function test_a_pending_payment_does_not_count_toward_the_balance(): void
    {
        $ctx = $this->scaffold('ver-pending');
        // A customer's unverified manual transfer sits pending.
        Payment::create([
            'organization_id' => $ctx['org']->id,
            'appointment_id' => $ctx['appointment']->id,
            'amount' => 8, 'method' => 'other',
            'status' => 'pending', 'source' => 'public_manual', 'reference' => 'TRX99',
        ]);

        $response = $this->withToken($ctx['ownerToken'])
            ->getJson("/api/appointments/{$ctx['appointment']->id}/invoice");

        $response->assertOk();
        $response->assertJsonPath('data.amount_paid', '0.00');
        $response->assertJsonPath('data.amount_pending', '8.00');
        $response->assertJsonPath('data.balance_due', '40.00');
        $response->assertJsonPath('data.payments.0.status', 'pending');
    }

    public function test_owner_verifies_a_pending_payment_and_it_then_counts(): void
    {
        $ctx = $this->scaffold('ver-ok');
        $payment = Payment::create([
            'organization_id' => $ctx['org']->id,
            'appointment_id' => $ctx['appointment']->id,
            'amount' => 8, 'method' => 'other',
            'status' => 'pending', 'source' => 'public_manual',
        ]);
        $url = "/api/appointments/{$ctx['appointment']->id}/payments/{$payment->id}/verify";

        // Staff cannot verify; owner can.
        $this->as($ctx, $ctx['staffToken'])->postJson($url)->assertForbidden();
        $this->as($ctx, $ctx['ownerToken'])->postJson($url)
            ->assertOk()->assertJsonPath('data.status', 'verified');

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'verified']);

        $this->as($ctx, $ctx['ownerToken'])
            ->getJson("/api/appointments/{$ctx['appointment']->id}/invoice")
            ->assertJsonPath('data.amount_paid', '8.00');
    }
}
