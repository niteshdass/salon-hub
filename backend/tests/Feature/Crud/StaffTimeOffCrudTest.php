<?php

namespace Tests\Feature\Crud;

use App\Models\Organization;
use App\Models\StaffTimeOff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StaffTimeOffCrudTest extends TestCase
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

    public function test_owner_can_create_list_and_delete_staff_time_off(): void
    {
        [$org, , $token] = $this->makeOrgWithOwner('alpha');
        $staff = $this->makeStaff($org, 'alpha');

        // Create.
        $create = $this->withToken($token)->postJson("/api/staff/{$staff->id}/time-off", [
            'start_at' => '2026-08-03 00:00:00',
            'end_at' => '2026-08-07 23:59:59',
            'reason' => 'Vacation',
        ]);
        $create->assertCreated();
        $create->assertJsonPath('data.reason', 'Vacation');
        $id = $create->json('data.id');

        $this->assertDatabaseHas('staff_time_off', [
            'id' => $id,
            'organization_id' => $org->id,
            'user_id' => $staff->id,
            'reason' => 'Vacation',
        ]);

        // List.
        $list = $this->withToken($token)->getJson("/api/staff/{$staff->id}/time-off");
        $list->assertOk();
        $this->assertCount(1, $list->json('data'));

        // Delete.
        $this->withToken($token)->deleteJson("/api/staff/{$staff->id}/time-off/{$id}")
            ->assertNoContent();
        $this->assertDatabaseMissing('staff_time_off', ['id' => $id]);
    }

    public function test_staff_cannot_manage_time_off(): void
    {
        [$org] = $this->makeOrgWithOwner('bravo');
        $staff = $this->makeStaff($org, 'bravo');
        $staffToken = $staff->createToken('api')->plainTextToken;

        $this->withToken($staffToken)->postJson("/api/staff/{$staff->id}/time-off", [
            'start_at' => '2026-08-03 00:00:00',
            'end_at' => '2026-08-04 00:00:00',
        ])->assertForbidden();
    }

    public function test_end_must_be_after_start(): void
    {
        [$org, , $token] = $this->makeOrgWithOwner('charlie');
        $staff = $this->makeStaff($org, 'charlie');

        $this->withToken($token)->postJson("/api/staff/{$staff->id}/time-off", [
            'start_at' => '2026-08-07 12:00:00',
            'end_at' => '2026-08-07 11:00:00',
        ])->assertStatus(422)->assertJsonValidationErrors('end_at');
    }

    public function test_cannot_create_time_off_for_another_orgs_staff(): void
    {
        [, , $token] = $this->makeOrgWithOwner('delta');
        [$otherOrg] = $this->makeOrgWithOwner('delta-other');
        $foreignStaff = $this->makeStaff($otherOrg, 'delta-other');

        // The staff id belongs to another tenant, so the nested route 404s.
        $this->withToken($token)->postJson("/api/staff/{$foreignStaff->id}/time-off", [
            'start_at' => '2026-08-03 00:00:00',
            'end_at' => '2026-08-04 00:00:00',
        ])->assertNotFound();
    }

    public function test_cannot_delete_time_off_belonging_to_another_staff(): void
    {
        [$org, , $token] = $this->makeOrgWithOwner('echo');
        $staffA = $this->makeStaff($org, 'echo-a');
        $staffB = $this->makeStaff($org, 'echo-b');

        $timeOff = StaffTimeOff::create([
            'organization_id' => $org->id,
            'user_id' => $staffB->id,
            'start_at' => '2026-08-03 00:00:00',
            'end_at' => '2026-08-04 00:00:00',
        ]);

        // Correct staff route param is staffB; using staffA must 404.
        $this->withToken($token)->deleteJson("/api/staff/{$staffA->id}/time-off/{$timeOff->id}")
            ->assertNotFound();
        $this->assertDatabaseHas('staff_time_off', ['id' => $timeOff->id]);
    }
}
