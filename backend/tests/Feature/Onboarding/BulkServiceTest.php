<?php

namespace Tests\Feature\Onboarding;

use App\Models\Organization;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BulkServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Organization, 1: string}
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

        return [$org, $owner->createToken('api')->plainTextToken];
    }

    public function test_it_creates_every_row_under_one_new_category(): void
    {
        [$org, $token] = $this->makeOrgWithOwner('alpha');

        $response = $this->withToken($token)->postJson('/api/services/bulk', [
            'category' => 'Hair salon',
            'rows' => [
                ['name' => 'Hair cut', 'duration' => 30, 'price' => 12.5],
                ['name' => 'Hair colour', 'duration' => 90, 'price' => 40],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonCount(2, 'data');

        $this->assertSame(1, ServiceCategory::where('organization_id', $org->id)->count());
        $categoryId = ServiceCategory::where('organization_id', $org->id)->value('id');

        $this->assertDatabaseHas('services', [
            'organization_id' => $org->id,
            'category_id' => $categoryId,
            'name' => 'Hair cut',
            'duration' => 30,
            'status' => 'active',
        ]);
    }

    public function test_a_second_call_for_the_same_type_reuses_the_category(): void
    {
        [$org, $token] = $this->makeOrgWithOwner('alpha');
        $payload = [
            'category' => 'Hair salon',
            'rows' => [['name' => 'Trim', 'duration' => 20, 'price' => 5]],
        ];

        $this->withToken($token)->postJson('/api/services/bulk', $payload)->assertCreated();
        $this->withToken($token)->postJson('/api/services/bulk', $payload)->assertCreated();

        $this->assertSame(1, ServiceCategory::where('organization_id', $org->id)->count());
    }

    public function test_a_row_without_a_price_fails_and_nothing_is_written(): void
    {
        [$org, $token] = $this->makeOrgWithOwner('alpha');

        $response = $this->withToken($token)->postJson('/api/services/bulk', [
            'category' => 'Hair salon',
            'rows' => [
                ['name' => 'Hair cut', 'duration' => 30, 'price' => 12.5],
                ['name' => 'Hair colour', 'duration' => 90],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('rows.1.price');
        $this->assertDatabaseCount('services', 0);
        $this->assertDatabaseCount('service_categories', 0);
    }

    public function test_it_rejects_an_empty_row_list(): void
    {
        [, $token] = $this->makeOrgWithOwner('alpha');

        $this->withToken($token)
            ->postJson('/api/services/bulk', ['category' => 'Hair salon', 'rows' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('rows');
    }

    public function test_a_staff_member_may_not_create_a_menu(): void
    {
        [$org] = $this->makeOrgWithOwner('alpha');
        $staff = User::create([
            'organization_id' => $org->id,
            'name' => 'Ruma',
            'email' => 'ruma@alpha.test',
            'password' => 'secret1234',
            'role' => 'staff',
            'status' => 'active',
        ]);

        $this->withToken($staff->createToken('api')->plainTextToken)
            ->postJson('/api/services/bulk', [
                'category' => 'Hair salon',
                'rows' => [['name' => 'Trim', 'duration' => 20, 'price' => 5]],
            ])
            ->assertForbidden();
    }

    public function test_one_salons_menu_never_lands_in_another(): void
    {
        [$orgA, $tokenA] = $this->makeOrgWithOwner('alpha');
        [$orgB] = $this->makeOrgWithOwner('bravo');

        $this->withToken($tokenA)->postJson('/api/services/bulk', [
            'category' => 'Hair salon',
            'rows' => [['name' => 'Trim', 'duration' => 20, 'price' => 5]],
        ])->assertCreated();

        $this->assertDatabaseCount('services', 1);
        $this->assertDatabaseHas('services', ['organization_id' => $orgA->id, 'name' => 'Trim']);
        $this->assertDatabaseMissing('services', ['organization_id' => $orgB->id]);
    }
}
