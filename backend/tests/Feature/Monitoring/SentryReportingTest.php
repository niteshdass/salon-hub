<?php

namespace Tests\Feature\Monitoring;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\State\Scope;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Tests\TestCase;

use function Sentry\configureScope;

/**
 * Task 13: Sentry reporting. No SENTRY_LARAVEL_DSN is set anywhere in this
 * test run (not in phpunit.xml, not in .env.example) — same as local dev
 * and CI — so these tests exercise exactly the "empty DSN" path production
 * itself will only be in until an operator fills in a real DSN.
 */
class SentryReportingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Organization, 1: User}
     */
    private function makeOrgWithOwner(string $slug): array
    {
        $org = Organization::create([
            'uuid' => (string) Str::uuid(),
            'name' => ucfirst($slug),
            'slug' => $slug,
            'email' => "owner@{$slug}.test",
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

        return [$org, $owner];
    }

    /**
     * Fix round 2: this test previously only asserted that a throwing route
     * still returns a normal 500 — true with or without Sentry reporting at
     * all, so it pinned nothing (mutation-tested by deleting the entire
     * `$exceptions->reportable(...)` block from bootstrap/app.php: still
     * "3 passed"). It is now a real, breakable assertion: the Sentry
     * client's transport is swapped for a spy (same technique as
     * SentryPiiCaptureTest) BEFORE hitting the throwing route, using the
     * real empty DSN this app ships with — no fake DSN here, unlike the PII
     * test — and asserts the spy never receives an event at all. This is
     * exactly what `bootstrap/app.php`'s `config('sentry.dsn')` guard
     * exists to guarantee; removing that guard (leaving only
     * `app()->bound('sentry')`) makes this test fail, because
     * `Integration::captureUnhandledException()` would then run
     * unconditionally and reach the spy transport regardless of DSN.
     */
    public function test_reporting_is_inert_with_empty_dsn(): void
    {
        $this->assertEmpty(config('sentry.dsn'));

        // The Sentry Hub is bound to the container unconditionally by the
        // package's own ServiceProvider::boot() — this is exactly why
        // bootstrap/app.php's reportable() callback and ResolveTenant's
        // Sentry tagging both also gate on `config('sentry.dsn')`, not
        // just on `app()->bound('sentry')`.
        $this->assertTrue(app()->bound('sentry'));

        /** @var ClientInterface $client */
        $client = app('sentry')->getClient();

        $spyTransport = new class implements TransportInterface
        {
            public ?Event $captured = null;

            public function send(Event $event): Result
            {
                $this->captured = $event;

                return new Result(ResultStatus::success(), $event);
            }

            public function close(?int $timeout = null): Result
            {
                return new Result(ResultStatus::success());
            }
        };

        $transportProperty = new \ReflectionProperty($client, 'transport');
        $transportProperty->setAccessible(true);
        $originalTransport = $transportProperty->getValue($client);
        $transportProperty->setValue($client, $spyTransport);

        try {
            // Register a route that always throws, so an unhandled exception
            // runs through the exact bootstrap/app.php `withExceptions`
            // reportable() callback added for this task. With no DSN it must
            // be a true no-op: the request still completes with a normal
            // error response instead of the callback itself blowing up.
            // Under `/api/...` so the SPA fallback route in web.php (which
            // excludes `api|storage|up` by design) does not swallow it first.
            Route::middleware('api')->get('/api/__test/sentry-throw', function () {
                throw new \RuntimeException('boom for Sentry inert test');
            });

            $response = $this->getJson('/api/__test/sentry-throw');

            $response->assertStatus(500);
        } finally {
            $transportProperty->setValue($client, $originalTransport);
        }

        $this->assertNull(
            $spyTransport->captured,
            'the empty-DSN guard did not hold: an event reached the transport despite config("sentry.dsn") being empty'
        );
    }

    public function test_tenant_tag_applied_to_sentry_scope_when_tenant_bound(): void
    {
        [$org, $owner] = $this->makeOrgWithOwner('sentrytag');
        $token = $owner->createToken('api')->plainTextToken;

        // Any route behind auth:sanctum + the `tenant` middleware resolves
        // and binds the organization via ResolveTenant, which is what tags
        // the Sentry scope.
        $this->withToken($token)->getJson('/api/settings/organization')->assertOk();

        $this->assertOrganizationTaggedOnScope($org);
    }

    public function test_tenant_tag_applied_to_sentry_scope_for_public_booking_flow(): void
    {
        $org = Organization::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Public Salon',
            'slug' => 'sentry-public-salon',
            'email' => 'owner@sentry-public-salon.test',
            'status' => 'active',
        ]);

        // The `public.tenant` middleware (ResolvePublicTenant) guards the
        // actual public booking flow — book, manage/{token}, payment
        // callbacks — not ResolveTenant. A booking-flow 500 on a salon
        // subdomain must be traceable to this same organization tag.
        $this->getJson('/api/public/sentry-public-salon')->assertOk();

        $this->assertOrganizationTaggedOnScope($org);
    }

    private function assertOrganizationTaggedOnScope(Organization $org): void
    {
        $scope = null;
        configureScope(function (Scope $s) use (&$scope): void {
            $scope = $s;
        });

        $this->assertNotNull($scope);

        $tags = $scope->applyToEvent(Event::createEvent())->getTags();

        $this->assertSame((string) $org->id, $tags['organization_id'] ?? null);
        $this->assertSame($org->slug, $tags['organization_slug'] ?? null);
    }
}
