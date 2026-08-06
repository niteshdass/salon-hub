<?php

namespace Tests\Feature\Tenancy;

use App\Http\Middleware\ResolveTenant;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The `tenant` middleware must fail CLOSED.
 *
 * BelongsToOrganization's global scope is inert when no tenant is bound —
 * that is deliberate and three shipped subsystems depend on it — so an
 * authenticated request that reaches a tenant-scoped route with no tenant
 * bound would read across every organization in the database rather than
 * none. These tests pin the boundary check in ResolveTenant that prevents it.
 */
class TenantFailClosedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Organization, 1: User}
     */
    private function makeOrgWithOwner(string $slug, string $status = 'active'): array
    {
        $org = Organization::create([
            'uuid' => (string) Str::uuid(),
            'name' => ucfirst($slug),
            'slug' => $slug,
            'email' => "owner@{$slug}.test",
            'subscription_plan' => 'free',
            'status' => $status,
        ]);

        $owner = User::create([
            'organization_id' => $org->id,
            'name' => "{$slug} owner",
            'email' => "owner@{$slug}.test",
            'password' => 'secret1234',
            'role' => 'owner',
            'status' => 'active',
        ]);

        Customer::create(['organization_id' => $org->id, 'name' => "{$slug} customer"]);

        return [$org, $owner];
    }

    public function test_an_authenticated_user_with_no_organization_gets_403_not_every_tenants_data(): void
    {
        [, $ownerA] = $this->makeOrgWithOwner('orphan-org');
        $this->makeOrgWithOwner('bystander');

        $token = $ownerA->createToken('api')->plainTextToken;

        // users.organization_id is nullable and UserFactory defaults it to
        // null, so "authenticated user, no organization" is reachable without
        // any exotic setup. It is also what a hand-written
        // `DELETE FROM organizations` leaves behind on a box running with
        // foreign_key_checks off — the ambient state of this project's local
        // MySQL. Either way $user->organization is null, and before the
        // boundary check that meant NO tenant was bound, which for
        // BelongsToOrganization means the global scope goes inert and this
        // request reads every organization's rows rather than none.
        DB::table('users')->where('id', $ownerA->id)->update(['organization_id' => null]);

        $this->assertNull($ownerA->fresh()->organization);

        $response = $this->withToken($token)->getJson('/api/customers');

        $response->assertForbidden();
        $response->assertJsonMissing(['name' => 'bystander customer']);
        $response->assertJsonMissing(['name' => 'orphan-org customer']);
    }

    public function test_deleting_an_organization_never_leaves_a_working_token_behind(): void
    {
        [$orgA, $ownerA] = $this->makeOrgWithOwner('vanishing-org');
        $this->makeOrgWithOwner('other-tenant');

        $token = $ownerA->createToken('api')->plainTextToken;

        DB::table('organizations')->where('id', $orgA->id)->delete();

        // Under FK enforcement the users cascade away with the organization,
        // so this is a 401; with enforcement off the user survives orphaned
        // and the boundary check makes it a 403. The assertion that matters
        // is the one both share: never a 200 carrying another tenant's rows.
        $response = $this->withToken($token)->getJson('/api/customers');

        $this->assertContains($response->status(), [401, 403]);
        $response->assertJsonMissing(['name' => 'other-tenant customer']);
    }

    public function test_a_member_of_a_suspended_organization_is_refused_the_dashboard(): void
    {
        [, $owner] = $this->makeOrgWithOwner('suspended-salon', 'suspended');

        $token = $owner->createToken('api')->plainTextToken;

        $this->withToken($token)->getJson('/api/customers')->assertForbidden();
        $this->withToken($token)->getJson('/api/dashboard')->assertForbidden();
    }

    public function test_a_member_of_an_inactive_organization_is_refused_the_dashboard(): void
    {
        [, $owner] = $this->makeOrgWithOwner('inactive-salon', 'inactive');

        $token = $owner->createToken('api')->plainTextToken;

        $this->withToken($token)->getJson('/api/customers')->assertForbidden();
    }

    /**
     * The session path, not the data path.
     *
     * /api/auth/me is what the SPA calls to turn a stored token into a
     * session. It used to carry only `api, Authenticate:sanctum`, so a
     * suspended salon's owner got 200 with their full user and organization,
     * the router admitted them into the dashboard shell, and every panel
     * behind it 403'd one at a time with no statement of what was wrong.
     * Refusing here is what makes suspension mean the same thing to the
     * session as it already meant to the data.
     */
    public function test_the_session_endpoint_refuses_a_suspended_organization_and_names_the_reason(): void
    {
        [, $owner] = $this->makeOrgWithOwner('suspended-session', 'suspended');

        $token = $owner->createToken('api')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/auth/me');

        $response->assertForbidden();
        $this->assertStringContainsString('suspended', strtolower((string) $response->json('message')));

        // The point of the finding: it must not hand back the organization.
        $response->assertJsonMissing(['slug' => 'suspended-session']);
    }

    public function test_the_session_endpoint_refuses_an_inactive_organization_and_names_the_reason(): void
    {
        [, $owner] = $this->makeOrgWithOwner('inactive-session', 'inactive');

        $token = $owner->createToken('api')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/auth/me');

        $response->assertForbidden();
        $this->assertStringContainsString('inactive', strtolower((string) $response->json('message')));
    }

    public function test_the_session_endpoint_still_answers_for_an_active_organization(): void
    {
        [, $owner] = $this->makeOrgWithOwner('live-session');

        $token = $owner->createToken('api')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/auth/me');

        $response->assertOk();
        $this->assertSame('live-session', $response->json('organization.slug'));
    }

    /**
     * Signing out is the one authenticated action a refused member must keep.
     * Without it the only way to drop a dead session is to clear browser
     * storage by hand, which no user knows how to do.
     */
    public function test_a_suspended_member_can_still_sign_out(): void
    {
        [, $owner] = $this->makeOrgWithOwner('sign-me-out', 'suspended');

        $token = $owner->createToken('api')->plainTextToken;

        $this->withToken($token)->postJson('/api/auth/logout')->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    /**
     * Minor 3. AuthController::me() dereferenced $user->organization with no
     * guard and 500'd for an org-less user. The previous round dismissed it
     * as unreachable "now that ResolveTenant fails closed on the same route
     * group" — but /api/auth/me was not in that group, which is the whole of
     * the finding above. The middleware is on the route now; this asserts the
     * controller does not depend on that being true.
     */
    public function test_the_session_endpoint_refuses_an_org_less_user_rather_than_500ing(): void
    {
        [, $owner] = $this->makeOrgWithOwner('about-to-be-orphaned');

        $token = $owner->createToken('api')->plainTextToken;

        DB::table('users')->where('id', $owner->id)->update(['organization_id' => null]);

        // Deliberately without the boundary check, so this exercises the
        // controller's own guard rather than the middleware's.
        $response = $this->withoutMiddleware(ResolveTenant::class)
            ->withToken($token)
            ->getJson('/api/auth/me');

        $response->assertForbidden();
        $this->assertNotSame(500, $response->status());
        $this->assertStringContainsString('not linked', strtolower((string) $response->json('message')));
    }

    public function test_an_active_organization_is_unaffected(): void
    {
        [, $owner] = $this->makeOrgWithOwner('healthy-salon');

        $token = $owner->createToken('api')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/customers');

        $response->assertOk();
        $this->assertSame('healthy-salon customer', $response->json('data.0.name'));
    }

    public function test_login_refuses_a_suspended_organization_without_minting_a_token(): void
    {
        [, $owner] = $this->makeOrgWithOwner('locked-out', 'suspended');

        $response = $this->postJson('/api/auth/login', [
            'email' => $owner->email,
            'password' => 'secret1234',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');

        // M6: the token used to be created before the organization was
        // dereferenced, leaking a personal_access_tokens row per attempt.
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_refuses_an_org_less_user_explicitly_rather_than_by_null_pointer(): void
    {
        $user = User::create([
            'organization_id' => null,
            'name' => 'Nobody',
            'email' => 'nobody@example.test',
            'password' => 'secret1234',
            'role' => 'owner',
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'secret1234',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
