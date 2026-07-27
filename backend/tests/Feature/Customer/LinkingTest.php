<?php

namespace Tests\Feature\Customer;

use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\CustomerLoginCode;
use App\Models\Organization;
use App\Mail\CustomerLoginCodeMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class LinkingTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrg(string $slug): Organization
    {
        return Organization::create([
            'uuid' => (string) Str::uuid(), 'name' => ucfirst($slug), 'slug' => $slug,
            'email' => "owner@{$slug}.test", 'subscription_plan' => 'free', 'status' => 'active',
        ]);
    }

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

    private function verify(string $email): void
    {
        $code = $this->loginCodeFor($email);
        $this->postJson('/api/customer/auth/verify-code', ['email' => $email, 'code' => $code])->assertOk();
    }

    public function test_verify_claims_matching_customer_rows_across_salons(): void
    {
        $orgA = $this->makeOrg('acme');
        $orgB = $this->makeOrg('glow');
        $mine1 = Customer::create(['organization_id' => $orgA->id, 'name' => 'Jane', 'phone' => '111', 'email' => 'jane@x.test']);
        $mine2 = Customer::create(['organization_id' => $orgB->id, 'name' => 'Jane', 'phone' => '222', 'email' => 'jane@x.test']);
        $notMine = Customer::create(['organization_id' => $orgA->id, 'name' => 'Other', 'phone' => '333', 'email' => 'other@x.test']);

        $this->verify('jane@x.test');

        $account = CustomerAccount::where('email', 'jane@x.test')->first();
        $this->assertSame($account->id, $mine1->fresh()->customer_account_id);
        $this->assertSame($account->id, $mine2->fresh()->customer_account_id);
        $this->assertNull($notMine->fresh()->customer_account_id);
    }

    public function test_verify_is_idempotent_and_links_rows_created_since_last_login(): void
    {
        $org = $this->makeOrg('acme');
        $this->verify('jane@x.test');
        $account = CustomerAccount::where('email', 'jane@x.test')->first();

        // A row created after the first login is picked up on the next verify.
        $late = Customer::create(['organization_id' => $org->id, 'name' => 'Jane', 'phone' => '444', 'email' => 'jane@x.test']);
        $this->verify('jane@x.test');

        $this->assertSame($account->id, $late->fresh()->customer_account_id);
        $this->assertSame(1, CustomerAccount::where('email', 'jane@x.test')->count());
    }
}
