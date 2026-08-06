<?php

namespace Tests\Feature\Onboarding;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StaffWithoutEmailTest extends TestCase
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

    public function test_a_staff_member_can_be_added_with_only_a_name_and_phone(): void
    {
        [$org, $token] = $this->makeOrgWithOwner('alpha');

        $response = $this->withToken($token)->postJson('/api/staff', [
            'name' => 'Ruma',
            'phone' => '01712345678',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'Ruma');

        $email = User::where('organization_id', $org->id)->where('role', 'staff')->value('email');
        $this->assertStringEndsWith('@alpha.invalid', $email);
        $this->assertStringStartsWith('staff-', $email);
    }

    public function test_two_such_staff_do_not_collide(): void
    {
        [$org, $token] = $this->makeOrgWithOwner('alpha');

        $this->withToken($token)->postJson('/api/staff', ['name' => 'Ruma'])->assertCreated();
        $this->withToken($token)->postJson('/api/staff', ['name' => 'Shila'])->assertCreated();

        $emails = User::where('organization_id', $org->id)->where('role', 'staff')->pluck('email');

        $this->assertCount(2, $emails->unique());
    }

    public function test_the_solo_owner_gets_a_staff_row_of_their_own_and_keeps_their_owner_row(): void
    {
        [$org, $token] = $this->makeOrgWithOwner('alpha');

        // What the wizard's "I work alone" button posts: the owner's name,
        // no email, because their real address is already on their owner row
        // and users.email is unique.
        $this->withToken($token)->postJson('/api/staff', ['name' => 'alpha owner'])->assertCreated();

        $this->assertSame(1, User::where('organization_id', $org->id)->where('role', 'staff')->count());

        $owner = User::where('organization_id', $org->id)->where('role', 'owner')->first();
        $this->assertSame('owner@alpha.test', $owner->email);

        // Refresh: the point of this assertion is that the solo path left
        // the owner's row untouched in the database. An unrefreshed model
        // would only prove the in-memory object was never mutated, which
        // would still pass even if the endpoint had rewritten the row.
        $owner->refresh();
        $this->assertSame(UserRole::OWNER, $owner->role);
    }

    public function test_a_real_email_is_still_honoured(): void
    {
        [, $token] = $this->makeOrgWithOwner('alpha');

        $this->withToken($token)
            ->postJson('/api/staff', ['name' => 'Ruma', 'email' => 'ruma@example.com'])
            ->assertCreated()
            ->assertJsonPath('data.email', 'ruma@example.com');
    }

    public function test_a_duplicate_real_email_is_still_refused(): void
    {
        [, $token] = $this->makeOrgWithOwner('alpha');

        $this->withToken($token)
            ->postJson('/api/staff', ['name' => 'Ruma', 'email' => 'ruma@example.com'])
            ->assertCreated();

        $this->withToken($token)
            ->postJson('/api/staff', ['name' => 'Shila', 'email' => 'ruma@example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }
}
