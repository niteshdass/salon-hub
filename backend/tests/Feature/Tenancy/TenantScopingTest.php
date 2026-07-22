<?php

namespace Tests\Feature\Tenancy;

use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantScopingTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrgWithOwner(string $slug): array
    {
        $org = Organization::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'name' => ucfirst($slug),
            'slug' => $slug,
            'email' => "owner@{$slug}.test",
        ]);

        $owner = User::create([
            'organization_id' => $org->id,
            'name' => "{$slug} owner",
            'email' => "owner@{$slug}.test",
            'password' => 'secret1234',
            'role' => 'owner',
            'status' => 'active',
        ]);

        // Seed customers directly (bypass scope: no tenant bound here).
        Customer::create(['organization_id' => $org->id, 'name' => "{$slug} A"]);
        Customer::create(['organization_id' => $org->id, 'name' => "{$slug} B"]);

        return [$org, $owner];
    }

    public function test_global_scope_restricts_reads_to_current_tenant(): void
    {
        [$orgA] = $this->makeOrgWithOwner('alpha');
        [$orgB] = $this->makeOrgWithOwner('beta');

        // Unscoped (no tenant): sees all four.
        $this->assertSame(4, Customer::count());

        // Bind tenant A -> only A's two.
        app(CurrentTenant::class)->set($orgA);
        $this->assertSame(2, Customer::count());
        $this->assertTrue(Customer::pluck('organization_id')->every(fn ($id) => $id === $orgA->id));

        // B's customer id is invisible / returns null under tenant A.
        $bCustomerId = Customer::withoutGlobalScope('organization')
            ->where('organization_id', $orgB->id)->value('id');
        $this->assertNull(Customer::find($bCustomerId));

        app(CurrentTenant::class)->forget();
    }

    public function test_create_auto_fills_organization_id_from_tenant(): void
    {
        [$orgA] = $this->makeOrgWithOwner('gamma');
        app(CurrentTenant::class)->set($orgA);

        $c = Customer::create(['name' => 'No org given']);
        $this->assertSame($orgA->id, $c->fresh()->organization_id);

        app(CurrentTenant::class)->forget();
    }

    public function test_customers_endpoint_returns_only_authed_org(): void
    {
        [$orgA, $ownerA] = $this->makeOrgWithOwner('delta');
        [$orgB] = $this->makeOrgWithOwner('epsilon');

        $token = $ownerA->createToken('api')->plainTextToken;

        $res = $this->withToken($token)->getJson('/api/customers');
        $res->assertOk();

        $orgIds = collect($res->json('data'))->pluck('organization_id')->unique()->values();
        $this->assertSame([$orgA->id], $orgIds->all());
        $this->assertCount(2, $res->json('data'));
    }
}
