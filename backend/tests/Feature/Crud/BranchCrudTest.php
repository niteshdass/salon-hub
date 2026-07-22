<?php

namespace Tests\Feature\Crud;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BranchCrudTest extends TestCase
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

    public function test_owner_can_create_list_update_and_delete_a_branch(): void
    {
        [$orgA, , $token] = $this->makeOrgWithOwner('alpha');

        // Create.
        $create = $this->withToken($token)->postJson('/api/branches', [
            'name' => 'Downtown',
            'phone' => '+1 555 0100',
            'email' => 'downtown@alpha.test',
            'city' => 'Metropolis',
            'country' => 'US',
            'latitude' => 40.1,
            'longitude' => -73.9,
            'opening_hours_json' => ['mon' => ['09:00', '18:00']],
        ]);
        $create->assertCreated();
        $create->assertJsonPath('data.name', 'Downtown');
        $create->assertJsonPath('data.country', 'US');
        $branchId = $create->json('data.id');

        $this->assertDatabaseHas('branches', [
            'id' => $branchId,
            'organization_id' => $orgA->id,
            'name' => 'Downtown',
        ]);

        // List.
        $list = $this->withToken($token)->getJson('/api/branches');
        $list->assertOk();
        $this->assertCount(1, $list->json('data'));

        // Update.
        $update = $this->withToken($token)->putJson("/api/branches/{$branchId}", [
            'name' => 'Downtown HQ',
        ]);
        $update->assertOk();
        $update->assertJsonPath('data.name', 'Downtown HQ');

        // Delete.
        $delete = $this->withToken($token)->deleteJson("/api/branches/{$branchId}");
        $delete->assertNoContent();
        $this->assertDatabaseMissing('branches', ['id' => $branchId]);
    }

    public function test_second_branch_creation_hits_free_plan_limit(): void
    {
        [, , $token] = $this->makeOrgWithOwner('beta');

        $this->withToken($token)->postJson('/api/branches', ['name' => 'First'])
            ->assertCreated();

        $second = $this->withToken($token)->postJson('/api/branches', ['name' => 'Second']);
        $second->assertStatus(422);
        $second->assertJsonPath('message', 'Your free plan allows only 1 branch.');
    }

    public function test_branch_of_another_tenant_returns_404(): void
    {
        [, , $tokenA] = $this->makeOrgWithOwner('gamma');
        [$orgB] = $this->makeOrgWithOwner('delta');

        // Branch belonging to org B (created without a bound tenant).
        $branchB = Branch::create([
            'organization_id' => $orgB->id,
            'name' => 'Delta Branch',
        ]);

        $this->withToken($tokenA)->getJson("/api/branches/{$branchB->id}")
            ->assertNotFound();
        $this->withToken($tokenA)->putJson("/api/branches/{$branchB->id}", ['name' => 'Hijack'])
            ->assertNotFound();
        $this->withToken($tokenA)->deleteJson("/api/branches/{$branchB->id}")
            ->assertNotFound();
    }
}
