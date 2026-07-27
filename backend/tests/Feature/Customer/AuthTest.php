<?php

namespace Tests\Feature\Customer;

use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\CustomerLoginCode;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrg(string $slug = 'acme'): Organization
    {
        return Organization::create([
            'uuid' => (string) Str::uuid(),
            'name' => ucfirst($slug),
            'slug' => $slug,
            'email' => "owner@{$slug}.test",
            'subscription_plan' => 'free',
            'status' => 'active',
        ]);
    }

    public function test_customer_account_can_be_created_and_issue_a_token(): void
    {
        $account = CustomerAccount::create(['name' => 'Jane', 'email' => 'jane@x.test', 'phone' => '555']);
        $token = $account->createToken('customer')->plainTextToken;

        $this->assertNotEmpty($token);
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_type' => CustomerAccount::class,
            'tokenable_id' => $account->id,
        ]);
    }

    public function test_login_code_active_scope_excludes_expired_and_consumed(): void
    {
        $fresh = CustomerLoginCode::create(['email' => 'a@x.test', 'code_hash' => Hash::make('111111'), 'expires_at' => now()->addMinutes(10), 'attempts' => 0]);
        CustomerLoginCode::create(['email' => 'a@x.test', 'code_hash' => Hash::make('222222'), 'expires_at' => now()->subMinute(), 'attempts' => 0]);
        CustomerLoginCode::create(['email' => 'a@x.test', 'code_hash' => Hash::make('333333'), 'expires_at' => now()->addMinutes(10), 'attempts' => 0, 'consumed_at' => now()]);

        $active = CustomerLoginCode::where('email', 'a@x.test')->active()->get();

        $this->assertCount(1, $active);
        $this->assertSame($fresh->id, $active->first()->id);
    }

    public function test_customer_links_to_account(): void
    {
        $org = $this->makeOrg();
        $account = CustomerAccount::create(['email' => 'jane@x.test']);
        $customer = Customer::create(['organization_id' => $org->id, 'name' => 'Jane', 'phone' => '555', 'email' => 'jane@x.test', 'customer_account_id' => $account->id]);

        $this->assertSame($account->id, $customer->fresh()->account->id);
        $this->assertTrue($account->customers()->where('id', $customer->id)->exists());
    }
}
