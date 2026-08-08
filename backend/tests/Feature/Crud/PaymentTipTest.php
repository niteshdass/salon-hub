<?php

namespace Tests\Feature\Crud;

use App\Enums\PaymentMethod;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentTipTest extends TestCase
{
    use RefreshDatabase;

    private Appointment $appointment;

    private string $token;

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
        $branch = Branch::create(['organization_id' => $org->id, 'name' => 'Main']);
        $customer = Customer::create([
            'organization_id' => $org->id, 'name' => 'Casey', 'phone' => '+15550100',
        ]);
        $service = Service::create([
            'organization_id' => $org->id, 'name' => 'Haircut',
            'duration' => 30, 'price' => 40, 'status' => 'active',
        ]);

        $this->token = $owner->createToken('api')->plainTextToken;
        $this->appointment = Appointment::create([
            'organization_id' => $org->id, 'branch_id' => $branch->id,
            'customer_id' => $customer->id, 'staff_id' => $staff->id,
            'booking_date' => '2026-09-01', 'start_time' => '10:00:00',
            'end_time' => '10:30:00', 'price' => 40, 'status' => 'completed',
        ]);
        $this->appointment->lines()->create([
            'service_id' => $service->id, 'name' => 'Haircut',
            'price' => 40, 'duration' => 30, 'sort_order' => 0,
        ]);
    }

    public function test_a_payment_can_carry_a_tip(): void
    {
        $this->withToken($this->token)
            ->postJson("/api/appointments/{$this->appointment->id}/payments", [
                'amount' => 40, 'tip_amount' => 5, 'method' => PaymentMethod::CASH->value,
            ])
            ->assertCreated()
            ->assertJsonPath('data.tip_amount', '5.00');
    }

    public function test_a_tip_does_not_reduce_the_balance(): void
    {
        $this->withToken($this->token)
            ->postJson("/api/appointments/{$this->appointment->id}/payments", [
                'amount' => 30, 'tip_amount' => 10, 'method' => PaymentMethod::CASH->value,
            ])
            ->assertCreated();

        $fresh = $this->appointment->fresh()->load('payments');

        $this->assertSame('30.00', $fresh->amountPaid());
        $this->assertSame('10.00', $fresh->balanceDue());
        $this->assertSame('10.00', $fresh->tipsCollected());
    }

    public function test_a_settled_booking_can_still_take_a_tip_only_payment(): void
    {
        $this->withToken($this->token)
            ->postJson("/api/appointments/{$this->appointment->id}/payments", [
                'amount' => 40, 'method' => PaymentMethod::CASH->value,
            ])
            ->assertCreated();

        $this->withToken($this->token)
            ->postJson("/api/appointments/{$this->appointment->id}/payments", [
                'amount' => 0, 'tip_amount' => 6, 'method' => PaymentMethod::CASH->value,
            ])
            ->assertCreated();

        $this->assertSame('6.00', $this->appointment->fresh()->load('payments')->tipsCollected());
    }

    public function test_a_payment_of_nothing_at_all_is_rejected(): void
    {
        $this->withToken($this->token)
            ->postJson("/api/appointments/{$this->appointment->id}/payments", [
                'amount' => 0, 'tip_amount' => 0, 'method' => PaymentMethod::CASH->value,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');
    }

    public function test_a_negative_tip_is_rejected(): void
    {
        $this->withToken($this->token)
            ->postJson("/api/appointments/{$this->appointment->id}/payments", [
                'amount' => 40, 'tip_amount' => -1, 'method' => PaymentMethod::CASH->value,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tip_amount');
    }
}
