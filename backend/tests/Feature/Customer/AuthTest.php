<?php

namespace Tests\Feature\Customer;

use App\Mail\CustomerLoginCodeMail;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\CustomerLoginCode;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
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

    /** Request a code, then read the plaintext back off the DB row for the assertion. */
    private function requestCode(string $email): void
    {
        $this->postJson('/api/customer/auth/request-code', ['email' => $email])->assertOk();
    }

    public function test_request_code_stores_hashed_code_and_sends_mail(): void
    {
        Mail::fake();

        $this->postJson('/api/customer/auth/request-code', ['email' => 'jane@x.test'])
            ->assertOk();

        $row = CustomerLoginCode::where('email', 'jane@x.test')->active()->first();
        $this->assertNotNull($row);
        $this->assertNotSame('', $row->code_hash);
        Mail::assertSent(CustomerLoginCodeMail::class, fn ($m) => $m->hasTo('jane@x.test'));
    }

    public function test_request_code_returns_generic_ok_and_hashes_not_plaintext(): void
    {
        Mail::fake();
        $this->postJson('/api/customer/auth/request-code', ['email' => 'jane@x.test'])->assertOk();

        $row = CustomerLoginCode::where('email', 'jane@x.test')->first();
        // Six-digit code is never stored in the clear.
        $this->assertDoesNotMatchRegularExpression('/^\d{6}$/', $row->code_hash);
    }

    /** Drives the real flow: capture the code by faking mail and reading the mailable. */
    private function loginCodeFor(string $email): string
    {
        Mail::fake();
        $this->postJson('/api/customer/auth/request-code', ['email' => $email])->assertOk();
        $code = null;
        Mail::assertSent(CustomerLoginCodeMail::class, function ($m) use ($email, &$code) {
            if ($m->hasTo($email)) { $code = $m->code; return true; }
            return false;
        });
        return $code;
    }

    public function test_verify_code_creates_verified_account_and_returns_token(): void
    {
        $code = $this->loginCodeFor('jane@x.test');

        $res = $this->postJson('/api/customer/auth/verify-code', ['email' => 'jane@x.test', 'code' => $code]);

        $res->assertOk()->assertJsonStructure(['token', 'account' => ['id', 'name', 'email', 'phone']]);
        $account = CustomerAccount::where('email', 'jane@x.test')->first();
        $this->assertNotNull($account);
        $this->assertNotNull($account->email_verified_at);
        $this->assertDatabaseHas('customer_login_codes', ['email' => 'jane@x.test']);
        $this->assertNotNull(CustomerLoginCode::where('email', 'jane@x.test')->first()->consumed_at);
    }

    public function test_verify_code_is_idempotent_reuses_same_account(): void
    {
        $c1 = $this->loginCodeFor('jane@x.test');
        $this->postJson('/api/customer/auth/verify-code', ['email' => 'jane@x.test', 'code' => $c1])->assertOk();
        $c2 = $this->loginCodeFor('jane@x.test');
        $this->postJson('/api/customer/auth/verify-code', ['email' => 'jane@x.test', 'code' => $c2])->assertOk();

        $this->assertSame(1, CustomerAccount::where('email', 'jane@x.test')->count());
    }

    public function test_verify_code_rejects_wrong_code_and_increments_attempts(): void
    {
        $this->loginCodeFor('jane@x.test');
        $this->postJson('/api/customer/auth/verify-code', ['email' => 'jane@x.test', 'code' => '000000'])
            ->assertStatus(422);
        $this->assertSame(1, CustomerLoginCode::where('email', 'jane@x.test')->active()->first()->attempts);
    }

    public function test_verify_code_rejects_expired_code(): void
    {
        CustomerLoginCode::create(['email' => 'jane@x.test', 'code_hash' => \Hash::make('123456'), 'expires_at' => now()->subMinute(), 'attempts' => 0]);
        $this->postJson('/api/customer/auth/verify-code', ['email' => 'jane@x.test', 'code' => '123456'])
            ->assertStatus(422);
    }

    public function test_verify_code_locks_after_five_attempts(): void
    {
        $code = $this->loginCodeFor('jane@x.test');
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/customer/auth/verify-code', ['email' => 'jane@x.test', 'code' => '000000'])->assertStatus(422);
        }
        // Sixth try, even with the CORRECT code, is locked out.
        $this->postJson('/api/customer/auth/verify-code', ['email' => 'jane@x.test', 'code' => $code])
            ->assertStatus(429);
    }

    public function test_consumed_code_cannot_be_reused(): void
    {
        $code = $this->loginCodeFor('jane@x.test');
        $this->postJson('/api/customer/auth/verify-code', ['email' => 'jane@x.test', 'code' => $code])->assertOk();
        $this->postJson('/api/customer/auth/verify-code', ['email' => 'jane@x.test', 'code' => $code])->assertStatus(422);
    }

    public function test_me_and_logout(): void
    {
        $code = $this->loginCodeFor('jane@x.test');
        $token = $this->postJson('/api/customer/auth/verify-code', ['email' => 'jane@x.test', 'code' => $code])->json('token');

        $this->withToken($token)->getJson('/api/customer/auth/me')
            ->assertOk()->assertJsonPath('account.email', 'jane@x.test');

        $this->withToken($token)->postJson('/api/customer/auth/logout')->assertOk();

        // Sanctum memoizes the resolved user for the whole test app; force it
        // to re-resolve so this assertion reflects the now-revoked token.
        $this->app['auth']->forgetGuards();

        // Token revoked.
        $this->withToken($token)->getJson('/api/customer/auth/me')->assertUnauthorized();
    }

    public function test_guard_separation_staff_token_rejected_on_customer_route(): void
    {
        $org = $this->makeOrg();
        $staff = User::create(['organization_id' => $org->id, 'name' => 'Owner', 'email' => 'o@acme.test', 'password' => 'secret1234', 'role' => 'owner', 'status' => 'active']);
        $staffToken = $staff->createToken('api')->plainTextToken;

        $this->withToken($staffToken)->getJson('/api/customer/auth/me')->assertUnauthorized();
    }

    public function test_guard_separation_customer_token_rejected_on_staff_route(): void
    {
        $code = $this->loginCodeFor('jane@x.test');
        $customerToken = $this->postJson('/api/customer/auth/verify-code', ['email' => 'jane@x.test', 'code' => $code])->json('token');

        // A staff-only route (tenant group) must reject the customer token.
        $this->withToken($customerToken)->getJson('/api/dashboard')->assertUnauthorized();
    }
}
