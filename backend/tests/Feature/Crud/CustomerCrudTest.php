<?php

namespace Tests\Feature\Crud;

use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerCrudTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Organization, 1: User, 2: string}
     */
    private function makeOrgWithOwner(string $slug): array
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
            'organization_id' => $org->id,
            'name' => "{$slug} owner",
            'email' => "owner@{$slug}.test",
            'password' => 'secret1234',
            'role' => 'owner',
            'status' => 'active',
        ]);

        return [$org, $owner, $owner->createToken('api')->plainTextToken];
    }

    public function test_owner_can_create_list_update_and_delete_a_customer(): void
    {
        [$orgA, , $token] = $this->makeOrgWithOwner('alpha');

        // Create.
        $create = $this->withToken($token)->postJson('/api/customers', [
            'name' => 'Ada Client',
            'phone' => '+1 555 0100',
            'email' => 'ada@alpha.test',
            'notes' => 'Prefers morning slots.',
        ]);
        $create->assertCreated();
        $create->assertJsonPath('data.name', 'Ada Client');
        $create->assertJsonPath('data.phone', '+1 555 0100');
        $create->assertJsonPath('data.notes', 'Prefers morning slots.');
        $customerId = $create->json('data.id');

        $this->assertDatabaseHas('customers', [
            'id' => $customerId,
            'organization_id' => $orgA->id,
            'name' => 'Ada Client',
        ]);

        // List (Resource collection => {data:[...]}).
        $list = $this->withToken($token)->getJson('/api/customers');
        $list->assertOk();
        $this->assertCount(1, $list->json('data'));
        $this->assertSame('Ada Client', $list->json('data.0.name'));

        // Show.
        $show = $this->withToken($token)->getJson("/api/customers/{$customerId}");
        $show->assertOk();
        $show->assertJsonPath('data.id', $customerId);

        // Update.
        $update = $this->withToken($token)->putJson("/api/customers/{$customerId}", [
            'name' => 'Ada Lovelace',
            'notes' => 'VIP',
        ]);
        $update->assertOk();
        $update->assertJsonPath('data.name', 'Ada Lovelace');
        $update->assertJsonPath('data.notes', 'VIP');

        // Delete.
        $delete = $this->withToken($token)->deleteJson("/api/customers/{$customerId}");
        $delete->assertNoContent();
        $this->assertDatabaseMissing('customers', ['id' => $customerId]);
    }

    public function test_customer_of_another_tenant_returns_404(): void
    {
        [, , $tokenA] = $this->makeOrgWithOwner('beta');
        [$orgB] = $this->makeOrgWithOwner('gamma');

        // Customer belonging to org B (created without a bound tenant).
        $customerB = Customer::create([
            'organization_id' => $orgB->id,
            'name' => 'Gamma Client',
        ]);

        $this->withToken($tokenA)->getJson("/api/customers/{$customerB->id}")
            ->assertNotFound();
        $this->withToken($tokenA)->putJson("/api/customers/{$customerB->id}", ['name' => 'Hijack'])
            ->assertNotFound();
        $this->withToken($tokenA)->deleteJson("/api/customers/{$customerB->id}")
            ->assertNotFound();
    }
}
