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
    private function on(string $host, string $path = '/api/public-site/site'): string
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
            'domain' => "{$slug}.glowhub.com",
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

        $this->getJson($this->on('beauty-queen.glowhub.com'))
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
                $this->getJson($this->on('beauty-queen.glowhub.com', '/api/public-site/services'))
                    ->assertOk()->json('data'),
                'name'
            )
        );

        $this->assertSame(
            ['Rival Buzzcut'],
            array_column(
                $this->getJson($this->on('rival-cuts.glowhub.com', '/api/public-site/services'))
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
        // scope must reject it without revealing whether the id exists
        // elsewhere, so a cross-tenant id is a 422 validation failure.
        $this->getJson($this->on(
            'beauty-queen.glowhub.com',
            '/api/public-site/staff?service_ids[]='.$rivalService->id
        ))->assertStatus(422);

        // ...and the salon's own service on its own host still resolves, so
        // the rejection above is isolation and not a broken route.
        $this->getJson($this->on(
            'rival-cuts.glowhub.com',
            '/api/public-site/staff?service_ids[]='.$rivalService->id
        ))->assertOk();
    }

    /* ------------------------------------------------------------------ */
    /* Everything that must NOT resolve */
    /* ------------------------------------------------------------------ */

    public function test_an_unknown_host_is_a_404_not_another_salon(): void
    {
        $this->makeOrg('beauty-queen');

        $this->getJson($this->on('nobody.glowhub.com'))->assertStatus(404);
    }

    public function test_the_apex_host_does_not_resolve_to_any_salon(): void
    {
        $this->makeOrg('beauty-queen');

        $this->getJson($this->on('glowhub.com'))->assertStatus(404);
        $this->getJson($this->on('app.glowhub.com'))->assertStatus(404);
    }

    /**
     * A host that merely ends in our apex as a substring is a different
     * name. Only an exact Domain row answers, so this can never match.
     */
    public function test_a_lookalike_host_does_not_resolve(): void
    {
        $this->makeOrg('beauty-queen');

        $this->getJson($this->on('beauty-queen.glowhub.com.evil.test'))->assertStatus(404);
        $this->getJson($this->on('evil-beauty-queen.glowhub.com'))->assertStatus(404);
    }

    /**
     * The {org}-slug branch has always required an ACTIVE organization. Host
     * resolution must not be the more permissive of the two doors.
     */
    public function test_a_suspended_organizations_host_is_a_404(): void
    {
        $this->makeOrg('beauty-queen', ['status' => 'suspended']);

        $this->getJson($this->on('beauty-queen.glowhub.com'))->assertStatus(404);

        // The reference behaviour, same organization, by path.
        $this->getJson('/api/public/beauty-queen/site')->assertStatus(404);
    }

    public function test_an_inactive_organizations_host_is_a_404(): void
    {
        $this->makeOrg('beauty-queen', ['status' => 'inactive']);

        $this->getJson($this->on('beauty-queen.glowhub.com'))->assertStatus(404);
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

        $this->getJson($this->on('beauty-queen.glowhub.com'))->assertStatus(404);
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

        $this->withHeader('X-Forwarded-Host', 'beauty-queen.glowhub.com')
            ->getJson($this->on('glowhub.com'))
            ->assertStatus(404);

        $this->withHeader('X-Forwarded-Host', 'rival-cuts.glowhub.com')
            ->getJson($this->on('beauty-queen.glowhub.com'))
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
            'domain' => 'a.b.glowhub.com',
            'is_primary' => false,
            'is_verified' => false,
            'ssl_enabled' => false,
        ]);
        // Under our apex and one label deep, but NOT the row registration
        // minted. Only a later flow writes a second row, and a later flow owns
        // its own verification.
        $secondary = Domain::create([
            'organization_id' => $org->id,
            'domain' => 'extra.glowhub.com',
            'is_primary' => false,
            'is_verified' => false,
            'ssl_enabled' => false,
        ]);

        (require database_path('migrations/2026_08_05_100100_verify_existing_apex_domains.php'))->up();

        $this->assertTrue(Domain::where('domain', 'beauty-queen.glowhub.com')->first()->is_verified);
        // A host we do not control stays a claim, not a resolvable tenant.
        $this->assertFalse($custom->fresh()->is_verified);
        $this->assertFalse($deep->fresh()->is_verified);
        $this->assertFalse($secondary->fresh()->is_verified);

        $this->getJson($this->on('beauty-queen.glowhub.com'))
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

        $this->getJson($this->on('BEAUTY-QUEEN.Glowhub.com'))
            ->assertOk()
            ->assertJsonPath('data.slug', 'beauty-queen');
    }

    public function test_a_host_carrying_an_explicit_port_resolves(): void
    {
        $this->makeOrg('beauty-queen');

        $this->getJson($this->on('beauty-queen.glowhub.com:443'))
            ->assertOk()
            ->assertJsonPath('data.slug', 'beauty-queen');
    }

    public function test_a_host_with_a_trailing_root_dot_resolves(): void
    {
        $this->makeOrg('beauty-queen');

        $this->getJson($this->on('beauty-queen.glowhub.com.'))
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
        $this->getJson('/api/public/beauty-queen/staff?service_ids[]='.$service->id)
            ->assertOk();
    }

    /**
     * The two groups live under DIFFERENT literal first segments — `public/`
     * and `public-site/` — so no URI in one can ever be matched by a route in
     * the other, whatever anybody adds to either later. The names below are
     * the ones that collided while both groups shared the `public/` prefix:
     * `api/public/{org}` is two segments, exactly the shape of the old
     * `api/public/site|services|slots|book`, and the host group won.
     *
     * Direction one: on the apex, the bare two-segment path endpoint belongs
     * to the salon named in the path.
     */
    public function test_a_salon_with_a_route_shaped_slug_keeps_its_bare_path_endpoint(): void
    {
        foreach (['site', 'services', 'slots', 'book'] as $slug) {
            $this->makeOrg($slug);

            $this->getJson('http://glowhub.com/api/public/'.$slug)
                ->assertOk()
                ->assertJsonPath('data.slug', $slug);
        }
    }

    /**
     * Direction two, and the one that actually served the wrong tenant: the
     * same URL requested ON ANOTHER SALON'S HOST must still answer with the
     * salon named in the PATH. A path-scoped request carries its own tenant;
     * the Host must not be able to substitute a different one.
     */
    public function test_a_route_shaped_slug_is_not_overridden_by_the_host(): void
    {
        $this->makeOrg('beauty-queen');

        foreach (['site', 'services', 'slots', 'book'] as $slug) {
            $this->makeOrg($slug);

            $this->getJson('http://beauty-queen.glowhub.com/api/public/'.$slug)
                ->assertOk()
                ->assertJsonPath('data.slug', $slug);
        }
    }

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
     * And the host-resolved group is not reachable by path: on the apex there
     * is no tenant to read from the Host, so it 404s rather than falling
     * through to some salon.
     */
    public function test_the_host_resolved_group_does_not_answer_on_the_apex(): void
    {
        $this->makeOrg('site');

        $this->getJson('http://glowhub.com/api/public-site')->assertStatus(404);
        $this->getJson('http://glowhub.com/api/public-site/site')->assertStatus(404);
        $this->getJson('http://glowhub.com/api/public-site/services')->assertStatus(404);
    }

    /**
     * The bare host-resolved endpoint, the mirror of `api/public/{org}`. The
     * two groups are shape-for-shape symmetric, so a page reached with a slug
     * in the URL and the same page reached on a subdomain call the same set of
     * endpoints under a different prefix.
     */
    public function test_the_bare_host_resolved_endpoint_returns_the_host_salon(): void
    {
        $this->makeOrg('beauty-queen');
        $this->makeOrg('rival-cuts');

        $this->getJson('http://beauty-queen.glowhub.com/api/public-site')
            ->assertOk()
            ->assertJsonPath('data.slug', 'beauty-queen');
    }

    /* ------------------------------------------------------------------ */
    /* The SPA shell on a salon host */
    /* ------------------------------------------------------------------ */

    public function test_the_spa_shell_is_served_at_the_root_of_a_salon_host(): void
    {
        $this->makeOrg('beauty-queen');

        $this->get($this->on('beauty-queen.glowhub.com', '/'))
            ->assertOk()
            ->assertSee('id="app"', false);
    }
}
