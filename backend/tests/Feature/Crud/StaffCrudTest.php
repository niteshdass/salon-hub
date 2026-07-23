<?php

namespace Tests\Feature\Crud;

use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StaffCrudTest extends TestCase
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

    public function test_owner_can_create_staff_with_profile_and_synced_services(): void
    {
        [$orgA, , $token] = $this->makeOrgWithOwner('alpha');

        $service = Service::create([
            'organization_id' => $orgA->id,
            'name' => 'Haircut',
            'duration' => 30,
            'price' => 20,
        ]);

        $response = $this->withToken($token)->postJson('/api/staff', [
            'name' => 'Jane Stylist',
            'email' => 'jane@alpha.test',
            'phone' => '+1 555 0111',
            'designation' => 'Senior Stylist',
            'bio' => 'Ten years of experience.',
            'working_days_json' => [1, 2, 3, 4, 5],
            'working_hours_json' => ['start' => '09:00', 'end' => '18:00'],
            'service_ids' => [$service->id],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'Jane Stylist');
        $response->assertJsonPath('data.role', 'staff');
        $response->assertJsonPath('data.phone', '+1 555 0111');
        $response->assertJsonPath('data.designation', 'Senior Stylist');
        $response->assertJsonPath('data.services.0.id', $service->id);
        $response->assertJsonPath('data.working_days_json', [1, 2, 3, 4, 5]);
        $response->assertJsonPath('data.working_hours_json.start', '09:00');
        $response->assertJsonPath('data.working_hours_json.end', '18:00');

        $staffId = $response->json('data.id');

        // User row: role=staff, org set.
        $this->assertDatabaseHas('users', [
            'id' => $staffId,
            'organization_id' => $orgA->id,
            'email' => 'jane@alpha.test',
            'role' => 'staff',
            'status' => 'active',
        ]);

        // Profile created.
        $this->assertDatabaseHas('staff_profiles', [
            'user_id' => $staffId,
            'phone' => '+1 555 0111',
            'designation' => 'Senior Stylist',
        ]);

        // Services synced through the pivot.
        $this->assertDatabaseHas('staff_services', [
            'staff_id' => $staffId,
            'service_id' => $service->id,
        ]);
    }

    public function test_working_days_and_hours_are_validated(): void
    {
        [, , $token] = $this->makeOrgWithOwner('sigma');

        // Weekday out of 1..7 range and a malformed time are both rejected.
        $response = $this->withToken($token)->postJson('/api/staff', [
            'name' => 'Bad Schedule',
            'email' => 'bad@sigma.test',
            'working_days_json' => [0, 8],
            'working_hours_json' => ['start' => '9am', 'end' => '18:00'],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'working_days_json.0',
            'working_days_json.1',
            'working_hours_json.start',
        ]);
    }

    public function test_update_can_change_working_schedule(): void
    {
        [$orgA, , $token] = $this->makeOrgWithOwner('tau');

        $staff = User::factory()->create([
            'organization_id' => $orgA->id,
            'role' => 'staff',
            'status' => 'active',
        ]);

        $response = $this->withToken($token)->putJson("/api/staff/{$staff->id}", [
            'working_days_json' => [6, 7],
            'working_hours_json' => ['start' => '10:00', 'end' => '16:00'],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.working_days_json', [6, 7]);
        $response->assertJsonPath('data.working_hours_json.start', '10:00');
        $response->assertJsonPath('data.working_hours_json.end', '16:00');

        $this->assertDatabaseHas('staff_profiles', ['user_id' => $staff->id]);
    }

    public function test_eleventh_staff_hits_free_plan_limit(): void
    {
        [$orgA, , $token] = $this->makeOrgWithOwner('beta');

        // Seed 10 existing staff directly (owner does not count).
        User::factory()->count(10)->create([
            'organization_id' => $orgA->id,
            'role' => 'staff',
            'status' => 'active',
        ]);

        $response = $this->withToken($token)->postJson('/api/staff', [
            'name' => 'Eleventh',
            'email' => 'eleventh@beta.test',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Your free plan allows only 10 staff.');
    }

    public function test_staff_list_only_returns_current_org_staff(): void
    {
        [$orgA, , $tokenA] = $this->makeOrgWithOwner('gamma');
        [$orgB] = $this->makeOrgWithOwner('delta');

        $staffA = User::factory()->create([
            'organization_id' => $orgA->id,
            'role' => 'staff',
            'status' => 'active',
        ]);
        User::factory()->create([
            'organization_id' => $orgB->id,
            'role' => 'staff',
            'status' => 'active',
        ]);

        $list = $this->withToken($tokenA)->getJson('/api/staff');
        $list->assertOk();

        $ids = collect($list->json('data'))->pluck('id');
        $this->assertCount(1, $ids);
        $this->assertSame($staffA->id, $ids->first());
    }
}
