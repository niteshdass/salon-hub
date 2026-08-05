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

    /**
     * The slug is a DNS label, not a URL segment. Anything nginx-salon.conf's
     * `~^(?<slug>[a-z0-9-]+)\.` server_name or tenantHost.js's `/^[a-z0-9-]+$/`
     * would refuse must be rejected at registration — otherwise the salon's own
     * subdomain falls through to the default server and the SPA renders the
     * marketing landing page on it, with no error anywhere.
     *
     * @dataProvider invalidSlugProvider
     */
    public function test_registration_rejects_a_slug_that_is_not_a_valid_dns_label(string $slug): void
    {
        $this->postJson('/api/auth/register', [
            'salon_name' => 'Some Salon',
            'slug' => $slug,
            'email' => 'owner@dns-label.test',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertStatus(422)->assertJsonValidationErrors('slug');

        $this->assertNull(Organization::where('email', 'owner@dns-label.test')->first());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidSlugProvider(): array
    {
        return [
            'underscore' => ['beauty_queen'],
            'leading hyphen' => ['-lead'],
            'trailing hyphen' => ['trail-'],
            'over 63 octets' => [str_repeat('a', 64)],
            'embedded dot would claim a deeper host' => ['beauty.queen'],
            'space' => ['beauty queen'],
        ];
    }

    /**
     * A name long enough to overflow the DNS label limit must not mint a host
     * nginx cannot serve. Str::slug(str_repeat('beauty ', 20)) is 139 chars.
     */
    public function test_a_very_long_salon_name_yields_a_slug_within_the_dns_label_limit(): void
    {
        $this->postJson('/api/auth/register', [
            'salon_name' => trim(str_repeat('Beauty ', 20)),
            'email' => 'owner@long-name.test',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertCreated();

        $organization = Organization::where('email', 'owner@long-name.test')->firstOrFail();

        $this->assertLessThanOrEqual(63, strlen($organization->slug));
        $this->assertMatchesRegularExpression('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $organization->slug);
    }

    /**
     * Str::slug('💇') === '', which used to be accepted: the organization got
     * an empty slug and a Domain row of ".salonhub.com", unrecoverable because
     * there is no slug-change flow.
     */
    public function test_a_name_with_nothing_transliterable_falls_back_to_a_usable_slug(): void
    {
        $this->postJson('/api/auth/register', [
            'salon_name' => '💇',
            'email' => 'owner@emoji-salon.test',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertCreated();

        $organization = Organization::where('email', 'owner@emoji-salon.test')->firstOrFail();

        $this->assertSame('salon', $organization->slug);
        $this->assertSame(
            'salon.salonhub.com',
            Domain::where('organization_id', $organization->id)->value('domain')
        );
    }
}
