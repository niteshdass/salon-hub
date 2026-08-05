<?php

namespace Tests\Feature\Public;

use App\Enums\ServiceStatus;
use App\Models\Domain;
use App\Models\Organization;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The Host header now selects which salon's data is served. Every table in
 * this app carries organization_id against one shared database, so what is
 * asserted below is not routing convenience — it is the tenant isolation
 * boundary of the public booking site.
 */
class SubdomainResolutionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build an absolute test URL on the given host.
     *
     * Absolute, deliberately: `$this->withHeader('Host', ...)` does NOT set
     * the host of a test request. MakesHttpRequests hands the URI to
     * Symfony's Request::create, which overwrites HTTP_HOST from the URI's
     * own host — so a Host header passed as a header is silently discarded
     * and every such test would pass against `localhost` while proving
     * nothing about host resolution. The host must come from the URL.
     */
    private function on(string $host, string $path = '/api/public/site'): string
    {
        return 'http://'.$host.$path;
    }

    private function makeOrg(string $slug, array $orgAttributes = [], array $domainAttributes = []): Organization
    {
        $org = Organization::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'name' => ucfirst($slug),
            'slug' => $slug,
            'email' => "owner@{$slug}.test",
            'subscription_plan' => 'free',
            'status' => 'active',
        ], $orgAttributes));

        Domain::create(array_merge([
            'organization_id' => $org->id,
            'domain' => "{$slug}.salonhub.com",
            'is_primary' => true,
            'is_verified' => true,
            'ssl_enabled' => true,
        ], $domainAttributes));

        return $org;
    }

    private function makeService(Organization $org, string $name): Service
    {
        return Service::withoutGlobalScopes()->create([
            'organization_id' => $org->id,
            'name' => $name,
            'duration' => 30,
            'price' => 100,
            'status' => ServiceStatus::ACTIVE->value,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* The host header really is what selects the tenant */
    /* ------------------------------------------------------------------ */

    public function test_site_resolves_from_the_host_header_without_a_slug_in_the_path(): void
    {
        $this->makeOrg('beauty-queen');

        $this->getJson($this->on('beauty-queen.salonhub.com'))
            ->assertOk()
            ->assertJsonPath('data.slug', 'beauty-queen');
    }

    public function test_two_salons_on_two_hosts_never_see_each_others_services(): void
    {
        $queen = $this->makeOrg('beauty-queen');
        $rival = $this->makeOrg('rival-cuts');

        $this->makeService($queen, 'Queen Balayage');
        $this->makeService($rival, 'Rival Buzzcut');

        $this->assertSame(
            ['Queen Balayage'],
            array_column(
                $this->getJson($this->on('beauty-queen.salonhub.com', '/api/public/services'))
                    ->assertOk()->json('data'),
                'name'
            )
        );

        $this->assertSame(
            ['Rival Buzzcut'],
            array_column(
                $this->getJson($this->on('rival-cuts.salonhub.com', '/api/public/services'))
                    ->assertOk()->json('data'),
                'name'
            )
        );
    }

    public function test_a_host_cannot_reach_another_salons_service_by_id(): void
    {
        $queen = $this->makeOrg('beauty-queen');
        $rival = $this->makeOrg('rival-cuts');

        $this->makeService($queen, 'Queen Balayage');
        $rivalService = $this->makeService($rival, 'Rival Buzzcut');

        // The rival's service id, asked for on the queen's host. The tenant
        // scope must make it invisible rather than schedulable.
        $this->getJson($this->on(
            'beauty-queen.salonhub.com',
            '/api/public/services/'.$rivalService->id.'/staff'
        ))->assertStatus(404);

        // ...and the salon's own service on its own host still resolves, so
        // the 404 above is isolation and not a broken route.
        $this->getJson($this->on(
            'rival-cuts.salonhub.com',
            '/api/public/services/'.$rivalService->id.'/staff'
        ))->assertOk();
    }

    /* ------------------------------------------------------------------ */
    /* Everything that must NOT resolve */
    /* ------------------------------------------------------------------ */

    public function test_an_unknown_host_is_a_404_not_another_salon(): void
    {
        $this->makeOrg('beauty-queen');

        $this->getJson($this->on('nobody.salonhub.com'))->assertStatus(404);
    }

    public function test_the_apex_host_does_not_resolve_to_any_salon(): void
    {
        $this->makeOrg('beauty-queen');

        $this->getJson($this->on('salonhub.com'))->assertStatus(404);
        $this->getJson($this->on('app.salonhub.com'))->assertStatus(404);
    }

    /**
     * A host that merely ends in our apex as a substring is a different
     * name. Only an exact Domain row answers, so this can never match.
     */
    public function test_a_lookalike_host_does_not_resolve(): void
    {
        $this->makeOrg('beauty-queen');

        $this->getJson($this->on('beauty-queen.salonhub.com.evil.test'))->assertStatus(404);
        $this->getJson($this->on('evil-beauty-queen.salonhub.com'))->assertStatus(404);
    }

    /**
     * The {org}-slug branch has always required an ACTIVE organization. Host
     * resolution must not be the more permissive of the two doors.
     */
    public function test_a_suspended_organizations_host_is_a_404(): void
    {
        $this->makeOrg('beauty-queen', ['status' => 'suspended']);

        $this->getJson($this->on('beauty-queen.salonhub.com'))->assertStatus(404);

        // The reference behaviour, same organization, by path.
        $this->getJson('/api/public/beauty-queen/site')->assertStatus(404);
    }

    public function test_an_inactive_organizations_host_is_a_404(): void
    {
        $this->makeOrg('beauty-queen', ['status' => 'inactive']);

        $this->getJson($this->on('beauty-queen.salonhub.com'))->assertStatus(404);
        $this->getJson('/api/public/beauty-queen/site')->assertStatus(404);
    }

    /**
     * A Domain row is a claim until it is verified. The v1.2 custom-domain
     * flow will insert rows naming hosts the claimant may not control; if an
     * unverified row resolved, inserting one would be a takeover.
     */
    public function test_an_unverified_domain_row_does_not_resolve(): void
    {
        $this->makeOrg('beauty-queen', [], ['is_verified' => false]);

        $this->getJson($this->on('beauty-queen.salonhub.com'))->assertStatus(404);
    }

    /**
     * is_primary is deliberately NOT required: a v1.2 custom domain is a
     * second, non-primary row for a salon that already has a primary one.
     */
    public function test_a_verified_secondary_domain_resolves(): void
    {
        $org = $this->makeOrg('beauty-queen');

        Domain::create([
            'organization_id' => $org->id,
            'domain' => 'beautyqueen.example',
            'is_primary' => false,
            'is_verified' => true,
            'ssl_enabled' => true,
        ]);

        $this->getJson($this->on('beautyqueen.example'))
            ->assertOk()
            ->assertJsonPath('data.slug', 'beauty-queen');
    }

    /**
     * Trusted proxies are not configured — bootstrap/app.php never calls
     * trustProxies() and there is no config/trustedproxy.php — so Laravel's
     * TrustProxies middleware leaves the trusted-proxy list empty and Symfony
     * ignores X-Forwarded-Host entirely. A client cannot pick the tenant with
     * a header nginx did not set.
     */
    public function test_x_forwarded_host_cannot_select_the_tenant(): void
    {
        $this->makeOrg('beauty-queen');

        $this->withHeader('X-Forwarded-Host', 'beauty-queen.salonhub.com')
            ->getJson($this->on('salonhub.com'))
            ->assertStatus(404);

        $this->withHeader('X-Forwarded-Host', 'rival-cuts.salonhub.com')
            ->getJson($this->on('beauty-queen.salonhub.com'))
            ->assertOk()
            ->assertJsonPath('data.slug', 'beauty-queen');
    }

    /**
     * Rows written before this feature shipped carry is_verified = false and
     * would silently stop serving. The backfill migration lifts exactly the
     * hosts under our own apex — one label deep, the shape the wildcard vhost
     * and wildcard certificate cover — and nothing else.
     */
    public function test_the_backfill_verifies_pre_existing_apex_subdomains_only(): void
    {
        $org = $this->makeOrg('beauty-queen', [], ['is_verified' => false]);

        $custom = Domain::create([
            'organization_id' => $org->id,
            'domain' => 'beautyqueen.example',
            'is_primary' => false,
            'is_verified' => false,
            'ssl_enabled' => false,
        ]);
        $deep = Domain::create([
            'organization_id' => $org->id,
            'domain' => 'a.b.salonhub.com',
            'is_primary' => false,
            'is_verified' => false,
            'ssl_enabled' => false,
        ]);

        (require database_path('migrations/2026_08_05_100100_verify_existing_apex_domains.php'))->up();

        $this->assertTrue(Domain::where('domain', 'beauty-queen.salonhub.com')->first()->is_verified);
        // A host we do not control stays a claim, not a resolvable tenant.
        $this->assertFalse($custom->fresh()->is_verified);
        $this->assertFalse($deep->fresh()->is_verified);

        $this->getJson($this->on('beauty-queen.salonhub.com'))
            ->assertOk()
            ->assertJsonPath('data.slug', 'beauty-queen');
        $this->getJson($this->on('beautyqueen.example'))->assertStatus(404);
    }

    /* ------------------------------------------------------------------ */
    /* Host spellings: case, port, trailing root dot */
    /* ------------------------------------------------------------------ */

    public function test_an_uppercased_host_resolves(): void
    {
        $this->makeOrg('beauty-queen');

        $this->getJson($this->on('BEAUTY-QUEEN.SalonHub.com'))
            ->assertOk()
            ->assertJsonPath('data.slug', 'beauty-queen');
    }

    public function test_a_host_carrying_an_explicit_port_resolves(): void
    {
        $this->makeOrg('beauty-queen');

        $this->getJson($this->on('beauty-queen.salonhub.com:443'))
            ->assertOk()
            ->assertJsonPath('data.slug', 'beauty-queen');
    }

    public function test_a_host_with_a_trailing_root_dot_resolves(): void
    {
        $this->makeOrg('beauty-queen');

        $this->getJson($this->on('beauty-queen.salonhub.com.'))
            ->assertOk()
            ->assertJsonPath('data.slug', 'beauty-queen');
    }

    /* ------------------------------------------------------------------ */
    /* Route ordering, in both directions */
    /* ------------------------------------------------------------------ */

    public function test_the_path_scoped_public_routes_still_work(): void
    {
        $org = $this->makeOrg('beauty-queen');
        $service = $this->makeService($org, 'Queen Balayage');

        $this->getJson('/api/public/beauty-queen')->assertOk();
        $this->getJson('/api/public/beauty-queen/site')
            ->assertOk()
            ->assertJsonPath('data.slug', 'beauty-queen');
        $this->getJson('/api/public/beauty-queen/services')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Queen Balayage');
        $this->getJson('/api/public/beauty-queen/services/'.$service->id.'/staff')
            ->assertOk();
    }

    /**
     * Path-scoped routes are unreachable through the host-resolved group:
     * every one of them is at least three segments long and the new group's
     * URIs are two (plus the one four-segment `services/{service}/staff`,
     * whose path-scoped twin is five).
     */
    public function test_a_salon_slugged_site_keeps_its_path_scoped_routes(): void
    {
        $org = $this->makeOrg('site');
        $this->makeService($org, 'Reserved Word Trim');

        $this->getJson('/api/public/site/site')
            ->assertOk()
            ->assertJsonPath('data.slug', 'site');
        $this->getJson('/api/public/site/services')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Reserved Word Trim');
    }

    /**
     * The other direction of the same collision, made explicit: on a host
     * that is not a salon, `/api/public/site` is the host-resolved endpoint
     * and 404s. It does NOT fall through to the salon slugged `site` — which
     * is the safe direction of the trade, since falling through would mean
     * an apex request picking a tenant by accident.
     */
    public function test_the_reserved_prefix_is_host_resolved_not_slug_resolved(): void
    {
        $this->makeOrg('site');

        $this->getJson($this->on('salonhub.com'))->assertStatus(404);
    }

    /* ------------------------------------------------------------------ */
    /* The SPA shell on a salon host */
    /* ------------------------------------------------------------------ */

    public function test_the_spa_shell_is_served_at_the_root_of_a_salon_host(): void
    {
        $this->makeOrg('beauty-queen');

        $this->get($this->on('beauty-queen.salonhub.com', '/'))
            ->assertOk()
            ->assertSee('id="app"', false);
    }
}
