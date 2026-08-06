<?php

namespace Tests\Feature\Onboarding;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OnboardingStatusTest extends TestCase
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

    public function test_me_reports_a_fresh_organization_as_not_onboarded(): void
    {
        [, , $token] = $this->makeOrgWithOwner('alpha');

        $response = $this->withToken($token)->getJson('/api/auth/me');

        $response->assertOk();
        // assertJsonPath(..., null) alone can't tell "key present and null" apart from
        // "key absent" — Arr::get resolves a missing path to null too, so it would pass
        // even if OrganizationResource never exposed this field. The structural assertion
        // is what actually pins the key's presence; keep both, they guard different things.
        $response->assertJsonPath('organization.onboarding_completed_at', null);
        $response->assertJsonStructure(['organization' => ['onboarding_completed_at']]);
    }
}
