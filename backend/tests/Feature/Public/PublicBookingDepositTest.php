<?php

namespace Tests\Feature\Public;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\PaymentSetting;
use App\Models\Service;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicBookingDepositTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Active org with a branch, a $50 service, and a staff member who works
     * every day 09:00–17:00. Optionally a deposit policy.
     *
     * @return array<string, mixed>
     */
    private function scaffold(string $slug, ?array $payment = null): array
    {
        $org = Organization::create([
            'uuid' => (string) Str::uuid(), 'name' => ucfirst($slug), 'slug' => $slug,
            'email' => "owner@{$slug}.test", 'subscription_plan' => 'free', 'status' => 'active',
        ]);
        $branch = Branch::create(['organization_id' => $org->id, 'name' => 'Main']);
        $service = Service::create([
            'organization_id' => $org->id, 'name' => 'Colour',
            'duration' => 30, 'price' => 50, 'status' => 'active',
        ]);
        $staff = User::create([
            'organization_id' => $org->id, 'name' => 'Stylist',
            'email' => "stylist@{$slug}.test", 'password' => 'secret1234',
            'role' => 'staff', 'status' => 'active',
        ]);
        StaffProfile::create([
            'user_id' => $staff->id, 'designation' => 'Senior',
            'working_days_json' => [1, 2, 3, 4, 5, 6, 7],
            'working_hours_json' => ['start' => '09:00', 'end' => '17:00'],
        ]);
        $staff->services()->attach($service->id);

        if ($payment) {
            PaymentSetting::create(['organization_id' => $org->id] + $payment);
        }

        return compact('org', 'branch', 'service', 'staff');
    }

    private function nextMonday(): string
    {
        return Carbon::parse('next monday')->format('Y-m-d');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $ctx, array $overrides = []): array
    {
        return array_merge([
            'service_ids' => [$ctx['service']->id],
            'staff_id' => $ctx['staff']->id,
            'date' => $this->nextMonday(),
            'start_time' => '11:00',
            'customer' => ['name' => 'Deposit Dana', 'phone' => '555-2020'],
        ], $overrides);
    }

    public function test_public_profile_exposes_the_deposit_policy(): void
    {
        $this->scaffold('dep-profile', [
            'deposit_type' => 'percent', 'deposit_value' => 20,
            'manual_enabled' => true, 'manual_account_number' => 'ACME-12345',
            'manual_instructions' => 'bKash to this number',
        ]);

        $response = $this->getJson('/api/public/dep-profile');

        $response->assertOk();
        $response->assertJsonPath('data.payment.requires_deposit', true);
        $response->assertJsonPath('data.payment.deposit_type', 'percent');
        $response->assertJsonPath('data.payment.deposit_value', '20.00');
        $response->assertJsonPath('data.payment.manual.enabled', true);
        $response->assertJsonPath('data.payment.manual.account_number', 'ACME-12345');
        $response->assertJsonPath('data.payment.manual.instructions', 'bKash to this number');
    }

    public function test_a_required_deposit_blocks_booking_without_a_transaction_reference(): void
    {
        $ctx = $this->scaffold('dep-block', [
            'deposit_type' => 'percent', 'deposit_value' => 20,
            'manual_enabled' => true, 'manual_account_number' => 'ACME-12345',
        ]);

        $response = $this->postJson('/api/public/dep-block/book', $this->payload($ctx));

        $response->assertStatus(422);
        $this->assertDatabaseMissing('appointments', ['staff_id' => $ctx['staff']->id]);
    }

    public function test_a_deposit_reference_books_and_records_a_pending_payment(): void
    {
        $ctx = $this->scaffold('dep-ok', [
            'deposit_type' => 'percent', 'deposit_value' => 20,
            'manual_enabled' => true, 'manual_account_number' => 'ACME-12345',
        ]);

        $response = $this->postJson('/api/public/dep-ok/book', $this->payload($ctx, [
            'payment_reference' => 'TRX-77',
        ]));

        $response->assertStatus(201);
        // Deposit is 20% of the $50 service = $10, held pending until verified.
        $response->assertJsonPath('data.payment.deposit_required', true);
        $response->assertJsonPath('data.payment.amount_pending', '10.00');
        $response->assertJsonPath('data.payment.amount_paid', '0.00');
        $response->assertJsonPath('data.payment.balance_due', '50.00');

        $this->assertDatabaseHas('payments', [
            'amount' => '10.00',
            'status' => 'pending',
            'source' => 'public_manual',
            'reference' => 'TRX-77',
        ]);
    }

    public function test_a_salon_with_no_deposit_policy_books_without_a_reference(): void
    {
        $ctx = $this->scaffold('dep-none');

        $response = $this->postJson('/api/public/dep-none/book', $this->payload($ctx));

        $response->assertStatus(201);
        $response->assertJsonPath('data.payment.deposit_required', false);
        $this->assertDatabaseHas('appointments', ['staff_id' => $ctx['staff']->id]);
    }
}
