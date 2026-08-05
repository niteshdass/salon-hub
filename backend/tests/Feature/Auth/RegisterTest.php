<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Domain;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_creates_organization_owner_and_domain_and_returns_token(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'salon_name' => 'Glamour Studio',
            'email' => 'owner@glamour.test',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $response->assertCreated();
        $response->assertJsonStructure([
            'token',
            'user' => ['id', 'name', 'email', 'role', 'status', 'organization_id'],
            'organization' => ['id', 'uuid', 'name', 'slug', 'primary_domain'],
        ]);

        $this->assertNotEmpty($response->json('token'));

        // Organization created.
        $organization = Organization::where('slug', 'glamour-studio')->first();
        $this->assertNotNull($organization);
        $this->assertSame('Glamour Studio', $organization->name);

        // Owner user created with owner role.
        $owner = User::where('email', 'owner@glamour.test')->first();
        $this->assertNotNull($owner);
        $this->assertSame($organization->id, $owner->organization_id);
        $this->assertSame(UserRole::OWNER, $owner->role);

        // Primary domain created.
        $domain = Domain::where('organization_id', $organization->id)->where('is_primary', true)->first();
        $this->assertNotNull($domain);
        $this->assertSame('glamour-studio.salonhub.com', $domain->domain);

        // Settings row created.
        $this->assertNotNull(Setting::where('organization_id', $organization->id)->first());

        // Primary domain surfaced in response.
        $this->assertSame('glamour-studio.salonhub.com', $response->json('organization.primary_domain'));
    }

    public function test_registration_creates_a_default_branch_with_opening_hours(): void
    {
        $this->postJson('/api/auth/register', [
            'salon_name' => 'Beauty Queen',
            'name' => 'Rita Owner',
            'email' => 'rita@beautyqueen.test',
            'password' => 'secret1234',
            'password_confirmation' => 'secret1234',
        ])->assertStatus(201);

        $org = Organization::where('slug', 'beauty-queen')->firstOrFail();
        $branches = Branch::withoutGlobalScopes()
            ->where('organization_id', $org->id)
            ->get();

        $this->assertCount(1, $branches);
        $branch = $branches->first();
        $this->assertSame('Beauty Queen', $branch->name);
        // Monday open, Sunday closed — a salon can edit this, but never has
        // to before taking a first booking. Keys are the three-letter form
        // SlotGenerator indexes by (strtolower(format('D'))).
        $this->assertSame(['09:00', '18:00'], $branch->opening_hours_json['mon']);
        $this->assertNull($branch->opening_hours_json['sun']);
    }

    /**
     * A slug becomes a host: registration mints `<slug>.APP_DOMAIN` as a
     * VERIFIED domain row, and a verified row is what selects the tenant for
     * every request arriving on that host. So the platform's own hosts — the
     * dashboard on app., the marketing site on www. — must not be claimable
     * by signing up under that name.
     *
     * The throttle is skipped here on purpose: `throttle:3,1` guards
     * registration against a spam run and is asserted elsewhere; this test is
     * about the slug rule and needs more than three attempts to cover it.
     */
    public function test_registration_rejects_every_reserved_slug(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        foreach (Organization::RESERVED_SLUGS as $i => $reserved) {
            $this->postJson('/api/auth/register', [
                'salon_name' => 'Squatter '.$reserved,
                'slug' => $reserved,
                'email' => "squatter{$i}@example.test",
                'password' => 'secret123',
                'password_confirmation' => 'secret123',
            ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('slug');

            $this->assertNull(
                Organization::where('slug', $reserved)->first(),
                "The reserved slug [{$reserved}] was created anyway."
            );
            $this->assertNull(
                Domain::where('domain', $reserved.'.salonhub.com')->first(),
                "The reserved host [{$reserved}.salonhub.com] was minted anyway."
            );
        }
    }

    /**
     * The rule above only sees a slug the caller sent. A salon NAMED "App"
     * reaches the generator instead, so the generator has to refuse the same
     * list — otherwise the reserved host is claimed without anyone asking for
     * it.
     */
    public function test_a_salon_named_after_a_reserved_word_never_gets_the_reserved_slug(): void
    {
        $this->postJson('/api/auth/register', [
            'salon_name' => 'App',
            'email' => 'owner@app-salon.test',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertCreated();

        $organization = Organization::where('email', 'owner@app-salon.test')->firstOrFail();

        $this->assertSame('app-2', $organization->slug);
        $this->assertNull(Domain::where('domain', 'app.salonhub.com')->first());
        $this->assertSame(
            'app-2.salonhub.com',
            Domain::where('organization_id', $organization->id)->value('domain')
        );
    }
}
