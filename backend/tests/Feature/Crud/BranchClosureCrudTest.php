<?php

namespace Tests\Feature\Crud;

use App\Models\Branch;
use App\Models\BranchClosure;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BranchClosureCrudTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Organization, 1: User, 2: string} */
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

    private function makeStaff(Organization $org, string $slug): User
    {
        return User::create([
            'organization_id' => $org->id,
            'name' => "{$slug} stylist",
            'email' => "stylist@{$slug}.test",
            'password' => 'secret1234',
            'role' => 'staff',
            'status' => 'active',
        ]);
    }

    private function makeBranch(Organization $org, string $name): Branch
    {
        return Branch::create([
            'organization_id' => $org->id,
            'name' => $name,
        ]);
    }

    public function test_owner_can_create_list_and_delete_a_branch_closure(): void
    {
        [$org, , $token] = $this->makeOrgWithOwner('alpha');
        $branch = $this->makeBranch($org, 'Main');

        $create = $this->withToken($token)->postJson('/api/branch-closures', [
            'branch_id' => $branch->id,
            'start_date' => '2026-12-25',
            'end_date' => '2026-12-26',
            'reason' => 'Christmas',
        ]);
        $create->assertCreated();
        $create->assertJsonPath('data.branch_id', $branch->id);
        $create->assertJsonPath('data.reason', 'Christmas');
        $id = $create->json('data.id');

        $this->assertDatabaseHas('branch_closures', [
            'id' => $id,
            'organization_id' => $org->id,
            'branch_id' => $branch->id,
            'reason' => 'Christmas',
        ]);

        $list = $this->withToken($token)->getJson('/api/branch-closures');
        $list->assertOk();
        $this->assertCount(1, $list->json('data'));

        $this->withToken($token)->deleteJson("/api/branch-closures/{$id}")
            ->assertNoContent();
        $this->assertDatabaseMissing('branch_closures', ['id' => $id]);
    }

    public function test_owner_can_create_an_org_wide_closure_with_null_branch(): void
    {
        [$org, , $token] = $this->makeOrgWithOwner('bravo');

        $create = $this->withToken($token)->postJson('/api/branch-closures', [
            'branch_id' => null,
            'start_date' => '2027-01-01',
            'end_date' => '2027-01-01',
            'reason' => 'New Year',
        ]);
        $create->assertCreated();
        $create->assertJsonPath('data.branch_id', null);

        $this->assertDatabaseHas('branch_closures', [
            'organization_id' => $org->id,
            'branch_id' => null,
            'reason' => 'New Year',
        ]);
    }

    public function test_staff_cannot_manage_closures(): void
    {
        [$org] = $this->makeOrgWithOwner('charlie');
        $staff = $this->makeStaff($org, 'charlie');
        $staffToken = $staff->createToken('api')->plainTextToken;

        $this->withToken($staffToken)->postJson('/api/branch-closures', [
            'start_date' => '2026-12-25',
            'end_date' => '2026-12-26',
        ])->assertForbidden();
    }

    public function test_end_date_cannot_precede_start_date(): void
    {
        [, , $token] = $this->makeOrgWithOwner('delta');

        $this->withToken($token)->postJson('/api/branch-closures', [
            'start_date' => '2026-12-26',
            'end_date' => '2026-12-25',
        ])->assertStatus(422)->assertJsonValidationErrors('end_date');
    }

    public function test_cannot_create_closure_for_another_orgs_branch(): void
    {
        [, , $token] = $this->makeOrgWithOwner('echo');
        [$otherOrg] = $this->makeOrgWithOwner('echo-other');
        $foreignBranch = $this->makeBranch($otherOrg, 'Foreign');

        $this->withToken($token)->postJson('/api/branch-closures', [
            'branch_id' => $foreignBranch->id,
            'start_date' => '2026-12-25',
            'end_date' => '2026-12-26',
        ])->assertStatus(422)->assertJsonValidationErrors('branch_id');
    }

    public function test_cannot_delete_another_orgs_closure(): void
    {
        [, , $token] = $this->makeOrgWithOwner('foxtrot');
        [$otherOrg] = $this->makeOrgWithOwner('foxtrot-other');
        $foreignBranch = $this->makeBranch($otherOrg, 'Foreign');

        $closure = BranchClosure::create([
            'organization_id' => $otherOrg->id,
            'branch_id' => $foreignBranch->id,
            'start_date' => '2026-12-25',
            'end_date' => '2026-12-26',
        ]);

        // Global scope hides the foreign row, so it resolves to a 404.
        $this->withToken($token)->deleteJson("/api/branch-closures/{$closure->id}")
            ->assertNotFound();
        $this->assertDatabaseHas('branch_closures', ['id' => $closure->id]);
    }

    public function test_closures_list_is_scoped_to_the_tenant(): void
    {
        [$org, , $token] = $this->makeOrgWithOwner('golf');
        $branch = $this->makeBranch($org, 'Main');
        BranchClosure::create([
            'organization_id' => $org->id,
            'branch_id' => $branch->id,
            'start_date' => '2026-12-25',
            'end_date' => '2026-12-26',
        ]);

        [$otherOrg] = $this->makeOrgWithOwner('golf-other');
        BranchClosure::create([
            'organization_id' => $otherOrg->id,
            'branch_id' => null,
            'start_date' => '2027-01-01',
            'end_date' => '2027-01-01',
        ]);

        $list = $this->withToken($token)->getJson('/api/branch-closures');
        $list->assertOk();
        $this->assertCount(1, $list->json('data'));
    }
}
