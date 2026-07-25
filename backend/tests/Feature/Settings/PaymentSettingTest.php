<?php

namespace Tests\Feature\Settings;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentSettingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * An org with an owner and a staff member (+ tokens).
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

        return [
            'org' => $org,
            'ownerToken' => $owner->createToken('api')->plainTextToken,
            'staffToken' => $staff->createToken('api')->plainTextToken,
        ];
    }

    public function test_defaults_are_returned_when_nothing_is_configured(): void
    {
        $ctx = $this->scaffold('pay-def');

        $response = $this->withToken($ctx['ownerToken'])->getJson('/api/settings/payments');

        $response->assertOk();
        $response->assertJsonPath('data.deposit_type', 'none');
        $response->assertJsonPath('data.deposit_value', '0.00');
        $response->assertJsonPath('data.manual_enabled', false);
    }

    public function test_owner_saves_a_percentage_deposit_and_manual_details(): void
    {
        $ctx = $this->scaffold('pay-save');

        $response = $this->withToken($ctx['ownerToken'])->putJson('/api/settings/payments', [
            'deposit_type' => 'percent',
            'deposit_value' => 20,
            'manual_enabled' => true,
            'manual_account_number' => 'bKash 01700000000',
            'manual_instructions' => 'Send the deposit, then enter the TrxID.',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.deposit_type', 'percent');
        $response->assertJsonPath('data.deposit_value', '20.00');
        $response->assertJsonPath('data.manual_enabled', true);
        $response->assertJsonPath('data.manual_account_number', 'bKash 01700000000');

        $this->assertDatabaseHas('payment_settings', [
            'organization_id' => $ctx['org']->id,
            'deposit_type' => 'percent',
            'deposit_value' => 20,
            'manual_enabled' => true,
        ]);
    }

    public function test_deposit_value_is_required_for_a_non_none_type(): void
    {
        $ctx = $this->scaffold('pay-val');

        $this->withToken($ctx['ownerToken'])->putJson('/api/settings/payments', [
            'deposit_type' => 'percent',
            'deposit_value' => 0,
            'manual_enabled' => false,
        ])->assertStatus(422)->assertJsonValidationErrors('deposit_value');

        // A percentage above 100 makes no sense.
        $this->withToken($ctx['ownerToken'])->putJson('/api/settings/payments', [
            'deposit_type' => 'percent',
            'deposit_value' => 150,
            'manual_enabled' => false,
        ])->assertStatus(422)->assertJsonValidationErrors('deposit_value');
    }

    public function test_enabling_manual_requires_account_details(): void
    {
        $ctx = $this->scaffold('pay-manual');

        $this->withToken($ctx['ownerToken'])->putJson('/api/settings/payments', [
            'deposit_type' => 'fixed',
            'deposit_value' => 10,
            'manual_enabled' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('manual_account_number');
    }

    public function test_owner_connects_the_sslcommerz_gateway_and_secrets_never_return(): void
    {
        $ctx = $this->scaffold('pay-gw');

        $save = $this->withToken($ctx['ownerToken'])->putJson('/api/settings/payments', [
            'deposit_type' => 'none', 'deposit_value' => 0, 'manual_enabled' => false,
            'gateway' => 'sslcommerz',
            'gateway_sandbox' => true,
            'credentials' => ['store_id' => 'glow-store', 'store_passwd' => 'super-secret'],
        ]);

        $save->assertOk();
        $save->assertJsonPath('data.gateway', 'sslcommerz');
        $save->assertJsonPath('data.gateway_sandbox', true);
        $save->assertJsonPath('data.has_gateway_credentials', true);
        // The secret is never echoed back — not the store_id, not the password.
        $save->assertJsonMissing(['store_passwd' => 'super-secret']);
        $this->assertStringNotContainsString('super-secret', $save->getContent());

        $read = $this->withToken($ctx['ownerToken'])->getJson('/api/settings/payments');
        $read->assertJsonPath('data.has_gateway_credentials', true);
        $this->assertStringNotContainsString('super-secret', $read->getContent());
    }

    public function test_saving_again_without_a_password_keeps_the_stored_credentials(): void
    {
        $ctx = $this->scaffold('pay-gw-keep');

        $this->withToken($ctx['ownerToken'])->putJson('/api/settings/payments', [
            'deposit_type' => 'none', 'deposit_value' => 0, 'manual_enabled' => false,
            'gateway' => 'sslcommerz', 'gateway_sandbox' => true,
            'credentials' => ['store_id' => 'glow-store', 'store_passwd' => 'super-secret'],
        ])->assertOk();

        // Toggling to live mode without re-entering the password must not wipe it.
        $response = $this->withToken($ctx['ownerToken'])->putJson('/api/settings/payments', [
            'deposit_type' => 'none', 'deposit_value' => 0, 'manual_enabled' => false,
            'gateway' => 'sslcommerz', 'gateway_sandbox' => false,
            'credentials' => ['store_id' => 'glow-store', 'store_passwd' => ''],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.gateway_sandbox', false);
        $response->assertJsonPath('data.has_gateway_credentials', true);
    }

    public function test_selecting_the_gateway_without_credentials_is_allowed_for_later_setup(): void
    {
        $ctx = $this->scaffold('pay-gw-later');

        $response = $this->withToken($ctx['ownerToken'])->putJson('/api/settings/payments', [
            'deposit_type' => 'none', 'deposit_value' => 0, 'manual_enabled' => false,
            'gateway' => 'sslcommerz', 'gateway_sandbox' => true,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.gateway', 'sslcommerz');
        $response->assertJsonPath('data.has_gateway_credentials', false);
    }

    public function test_non_owner_cannot_read_or_write_payment_settings(): void
    {
        $ctx = $this->scaffold('pay-role');

        $this->withToken($ctx['staffToken'])->getJson('/api/settings/payments')->assertForbidden();
        $this->withToken($ctx['staffToken'])->putJson('/api/settings/payments', [
            'deposit_type' => 'none', 'deposit_value' => 0, 'manual_enabled' => false,
        ])->assertForbidden();
    }
}
