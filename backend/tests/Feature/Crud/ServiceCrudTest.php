<?php

namespace Tests\Feature\Crud;

use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ServiceCrudTest extends TestCase
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

    public function test_owner_can_create_a_category_and_service(): void
    {
        [$orgA, , $token] = $this->makeOrgWithOwner('alpha');

        $category = $this->withToken($token)->postJson('/api/categories', ['name' => 'Hair']);
        $category->assertCreated();
        $category->assertJsonPath('data.name', 'Hair');
        $categoryId = $category->json('data.id');

        $service = $this->withToken($token)->postJson('/api/services', [
            'name' => 'Haircut',
            'category_id' => $categoryId,
            'duration' => 30,
            'price' => 25.5,
        ]);
        $service->assertCreated();
        $service->assertJsonPath('data.name', 'Haircut');
        $service->assertJsonPath('data.status', 'active');
        $service->assertJsonPath('data.category.id', $categoryId);
        $service->assertJsonPath('data.category.name', 'Hair');

        $this->assertDatabaseHas('services', [
            'id' => $service->json('data.id'),
            'organization_id' => $orgA->id,
            'category_id' => $categoryId,
            'name' => 'Haircut',
        ]);
    }

    public function test_service_list_is_scoped_to_current_tenant(): void
    {
        [$orgA, , $tokenA] = $this->makeOrgWithOwner('beta');
        [$orgB] = $this->makeOrgWithOwner('gamma');

        Service::create([
            'organization_id' => $orgA->id,
            'name' => 'A Service',
            'duration' => 30,
            'price' => 10,
        ]);
        Service::create([
            'organization_id' => $orgB->id,
            'name' => 'B Service',
            'duration' => 30,
            'price' => 10,
        ]);

        $list = $this->withToken($tokenA)->getJson('/api/services');
        $list->assertOk();
        $this->assertCount(1, $list->json('data'));
        $this->assertSame('A Service', $list->json('data.0.name'));
    }

    public function test_creating_service_with_another_tenants_category_fails_validation(): void
    {
        [, , $tokenA] = $this->makeOrgWithOwner('delta');
        [$orgB] = $this->makeOrgWithOwner('epsilon');

        // Category belongs to org B.
        $categoryB = ServiceCategory::create([
            'organization_id' => $orgB->id,
            'name' => 'Nails',
        ]);

        $response = $this->withToken($tokenA)->postJson('/api/services', [
            'name' => 'Cross Tenant',
            'category_id' => $categoryB->id,
            'duration' => 30,
            'price' => 10,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('category_id');
    }
}
