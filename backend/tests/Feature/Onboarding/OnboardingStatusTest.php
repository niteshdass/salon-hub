<?php

namespace Tests\Feature\Onboarding;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\Service;
use App\Models\Setting;
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

    public function test_a_fresh_salon_has_no_step_done_and_starts_at_branch(): void
    {
        [$org, , $token] = $this->makeOrgWithOwner('alpha');
        $branch = Branch::create([
            'organization_id' => $org->id,
            'name' => 'Alpha',
        ]);

        $response = $this->withToken($token)->getJson('/api/onboarding/status');

        $response->assertOk();
        $response->assertJsonPath('data.steps.branch', false);
        $response->assertJsonPath('data.steps.services', false);
        $response->assertJsonPath('data.steps.staff', false);
        $response->assertJsonPath('data.steps.look', false);
        $response->assertJsonPath('data.next_step', 'branch');
        $response->assertJsonPath('data.branch_id', $branch->id);
    }

    public function test_each_step_flips_when_its_rows_exist(): void
    {
        [$org, , $token] = $this->makeOrgWithOwner('alpha');
        Branch::create([
            'organization_id' => $org->id,
            'name' => 'Alpha',
            'address' => '12 Green Road',
        ]);
        Service::create([
            'organization_id' => $org->id,
            'name' => 'Hair cut',
            'duration' => 30,
            'price' => 12,
            'status' => 'active',
        ]);
        User::create([
            'organization_id' => $org->id,
            'name' => 'Ruma',
            'email' => 'ruma@alpha.test',
            'password' => 'secret1234',
            'role' => 'staff',
            'status' => 'active',
        ]);
        Setting::create([
            'organization_id' => $org->id,
            'about' => 'We have cut hair on this street since 1998.',
        ]);

        $response = $this->withToken($token)->getJson('/api/onboarding/status');

        $response->assertJsonPath('data.steps.branch', true);
        $response->assertJsonPath('data.steps.services', true);
        $response->assertJsonPath('data.steps.staff', true);
        $response->assertJsonPath('data.steps.look', true);
        $response->assertJsonPath('data.next_step', 'done');
    }

    public function test_the_owner_of_another_salon_sees_only_their_own_progress(): void
    {
        [$orgA] = $this->makeOrgWithOwner('alpha');
        [, , $tokenB] = $this->makeOrgWithOwner('bravo');
        Service::create([
            'organization_id' => $orgA->id,
            'name' => 'Hair cut',
            'duration' => 30,
            'price' => 12,
            'status' => 'active',
        ]);

        $response = $this->withToken($tokenB)->getJson('/api/onboarding/status');

        $response->assertJsonPath('data.steps.services', false);
    }

    /**
     * Setting up the salon is the owner's job. Both non-owner roles go through
     * the same OrganizationPolicy::update -> isOwner() check, but "it will
     * behave the same" is an argument, not an assertion — a role-specific
     * bypass added to either route would only be caught by naming both.
     */
    public function test_non_owner_roles_may_not_read_or_complete_onboarding(): void
    {
        [$org] = $this->makeOrgWithOwner('alpha');

        foreach (['manager', 'staff'] as $role) {
            $member = User::create([
                'organization_id' => $org->id,
                'name' => ucfirst($role),
                'email' => "{$role}@alpha.test",
                'password' => 'secret1234',
                'role' => $role,
                'status' => 'active',
            ]);
            $token = $member->createToken('api')->plainTextToken;

            // Without this the second role is not actually tested. Every HTTP
            // call in one test method shares the application container, and
            // the sanctum guard caches the user it resolved on the first
            // request — so the `staff` iteration was silently re-answering as
            // `manager`, and stayed green even with a policy that explicitly
            // let staff through. Dropping the guards forces the new token to
            // be resolved from scratch.
            $this->app['auth']->forgetGuards();

            $this->withToken($token)->getJson('/api/onboarding/status')
                ->assertForbidden();
            $this->withToken($token)->postJson('/api/onboarding/complete')
                ->assertForbidden();
        }
    }

    public function test_completing_is_idempotent_and_keeps_the_first_timestamp(): void
    {
        [$org, , $token] = $this->makeOrgWithOwner('alpha');

        $first = $this->withToken($token)->postJson('/api/onboarding/complete');
        $first->assertOk();
        $this->assertNotNull($first->json('data.completed_at'));

        $stamped = $org->fresh()->onboarding_completed_at;

        // Both calls would otherwise land inside the same one-second-resolution
        // timestamp column, making a re-stamping implementation indistinguishable
        // from a correct one. Advance the clock so a re-stamp is visible.
        $this->travel(2)->seconds();

        $second = $this->withToken($token)->postJson('/api/onboarding/complete');
        $second->assertOk();

        $this->assertTrue($stamped->equalTo($org->fresh()->onboarding_completed_at));
    }

    public function test_the_look_step_is_done_with_only_a_logo_and_no_about(): void
    {
        [$org, , $token] = $this->makeOrgWithOwner('alpha');
        $org->logo = 'organizations/1/logo.png';
        $org->save();

        $response = $this->withToken($token)->getJson('/api/onboarding/status');

        $response->assertOk();
        $response->assertJsonPath('data.steps.look', true);
    }
}
