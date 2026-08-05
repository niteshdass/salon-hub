<?php

namespace Tests\Feature\Monitoring;

use App\Monitoring\SentryBeforeSend;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request as LaravelRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\Serializer\EnvelopItems\EventItem;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Tests\TestCase;

/**
 * Sends one representative request — carrying real-shaped secrets and
 * triggering a real, unhandled `QueryException` with bound customer data,
 * plus a real log call shaped exactly like `App\Reminders\LogReminderChannel`
 * — through the ACTUAL Sentry pipeline this app runs in production, then
 * asserts on the literal JSON payload the SDK would have sent to Sentry.
 *
 * "Actual pipeline" means: the real request never hits a manually-called
 * capture method. It is left uncaught, so it propagates through Laravel's
 * exception handling into the exact `$exceptions->reportable(...)` callback
 * `bootstrap/app.php` registers, which calls
 * `Sentry\Laravel\Integration::captureUnhandledException()`, the real
 * `Sentry\Laravel\Http\LaravelRequestFetcher`, the SDK's private
 * `RequestIntegration::processEvent` (only reachable via the real
 * event-processor chain — not directly callable), and this repo's
 * `App\Monitoring\SentryBeforeSend`. The only substitution anywhere is the
 * transport at the very last step — swapped via reflection for a spy that
 * records the fully-prepared `Event` instead of making an HTTP call — so
 * this proves what WOULD be sent once a real DSN is filled in, without
 * anything ever leaving the process.
 *
 * This is what makes the "reporting is inert with an empty DSN" test
 * (below, in SentryReportingTest) non-vacuous: this test proves the pipeline
 * this app ships actually redacts what it must, so that when a real DSN is
 * filled in, the inert path becomes this path — not an unreviewed one.
 */
class SentryPiiCaptureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * `Sentry\Laravel\ServiceProvider::boot()` only pushes
     * `SetRequestMiddleware` — the middleware that actually binds the
     * current request for `LaravelRequestFetcher` to find — when a DSN is
     * set ("no events can be sent without a DSN set" is checked before
     * request capture is even wired up, not just before transport). With
     * the empty DSN this app ships with, that middleware never runs, so
     * this proof cannot observe the real request-capture path without a DSN
     * present at boot. A syntactically valid but fake DSN is set here,
     * before the application boots, purely so the SAME request-capture
     * wiring production would use turns on; the transport is swapped for a
     * spy before anything is captured, so nothing is ever sent anywhere
     * real.
     */
    protected function setUp(): void
    {
        $fakeDsn = 'https://public@o0.ingest.sentry.io/0';
        putenv('SENTRY_LARAVEL_DSN='.$fakeDsn);
        $_ENV['SENTRY_LARAVEL_DSN'] = $fakeDsn;
        $_SERVER['SENTRY_LARAVEL_DSN'] = $fakeDsn;

        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv('SENTRY_LARAVEL_DSN');
        unset($_ENV['SENTRY_LARAVEL_DSN'], $_SERVER['SENTRY_LARAVEL_DSN']);

        parent::tearDown();
    }

    public function test_secrets_and_sql_bindings_are_scrubbed_from_the_real_captured_payload(): void
    {
        $secretPassword = 'CorrectHorseBattery9!';
        $secretOtp = '482913';
        $bearerToken = '1|abcdefghijklmnopqrstuvwxyz0123456789SECRETTOKEN';
        $manageToken = 'mgtok_'.str_repeat('a1B2', 8); // shaped like the unguessable manage/{token}
        $signature = 'deadbeef1234signature';
        $customerPhone = '+8801711223344';
        $customerEmail = 'victim@sentry-proof.test';

        /** @var ClientInterface $client */
        $client = app('sentry')->getClient();
        $this->assertNotNull($client, 'no Sentry client bound — cannot run the proof');

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
            // Same request shape as the endpoints named in the task brief:
            // JSON body with password + OTP (customer OTP login / staff
            // login), a bearer auth header (authenticated API calls), a
            // secret in the route (booking manage/{token}) and a
            // signed-URL query string (email verification link). The
            // handler additionally reproduces, verbatim, the two real leak
            // sites the review found: LogReminderChannel's log line, and an
            // uncaught QueryException carrying a bound customer email —
            // exactly what an ordinary DB fault on a booking write would
            // produce.
            Route::middleware('api')->post(
                '/api/__test/sentry-pii-capture/{manageToken}',
                function () use ($customerPhone, $customerEmail) {
                    // Reproduces App\Reminders\LogReminderChannel::send()
                    // exactly, so this test exercises the real leak site
                    // the review found, not a stand-in for it.
                    Log::info("[reminder] to={$customerPhone} :: Reminder: Haircut at Salon on Monday, 9:00 AM. See you soon!");

                    // An ordinary DB fault with a bound customer value,
                    // left uncaught so it propagates through Laravel's real
                    // exception handling into bootstrap/app.php's
                    // reportable() callback — not a manually-invoked
                    // capture call.
                    DB::select(
                        'select * from no_such_table_for_sentry_proof where email = ?',
                        [$customerEmail]
                    );

                    return response()->json(['ok' => true]);
                }
            );

            $response = $this->withToken($bearerToken)->postJson(
                '/api/__test/sentry-pii-capture/'.$manageToken.'?password='.$secretPassword.'&signature='.$signature,
                [
                    'email' => 'customer@example.com',
                    'password' => $secretPassword,
                    'otp' => $secretOtp,
                    'code' => $secretOtp,
                ]
            );

            // The QueryException is uncaught by design (see above); Laravel
            // renders it as a normal 500 JSON error response.
            $response->assertStatus(500);
        } finally {
            $transportProperty->setValue($client, $originalTransport);
        }

        $capturedEvent = $spyTransport->captured;
        $this->assertNotNull($capturedEvent, 'the spy transport never received an event — the reportable() pipeline did not run');

        // The literal JSON payload the SDK would have sent to Sentry for
        // this event — the same serializer `PayloadSerializer` uses to
        // build the real envelope body. Not a hand-summarised shape.
        // `toEnvelopeItem()` returns two newline-separated JSON lines — an
        // item header, then the actual payload body — matching the real
        // envelope wire format (https://develop.sentry.dev/sdk/envelopes/).
        // The payload body (second line) is what carries request data,
        // breadcrumbs and the exception/stacktrace.
        $envelopeItem = EventItem::toEnvelopeItem($capturedEvent);
        [, $payloadJson] = explode("\n", $envelopeItem, 2);
        $payload = json_decode($payloadJson, true);
        $this->assertIsArray($payload, 'captured payload did not decode as JSON');

        // Secrets must not appear anywhere in the literal bytes that would
        // have been sent.
        $this->assertStringNotContainsString($secretPassword, $payloadJson);
        $this->assertStringNotContainsString($secretOtp, $payloadJson);
        $this->assertStringNotContainsString($bearerToken, $payloadJson);
        $this->assertStringNotContainsString($manageToken, $payloadJson);
        $this->assertStringNotContainsString($signature, $payloadJson);
        $this->assertStringNotContainsString($customerPhone, $payloadJson);
        $this->assertStringNotContainsString($customerEmail, $payloadJson);

        // Prove WHY, not just that the strings happen not to appear:

        // C1 sanity: config:cache must not have broken the wiring — the
        // callable actually ran (proven by the assertions above passing at
        // all) and is the array-callable form, not a closure.
        $this->assertSame([SentryBeforeSend::class, 'handle'], config('sentry.before_send'));

        // I4: no `data` (body) key at all — max_request_body_size=none
        // blocks capture regardless of PII settings — and no `query_string`
        // key — SentryBeforeSend strips it unconditionally.
        $requestData = $payload['request'] ?? [];
        $this->assertArrayNotHasKey('data', $requestData);
        $this->assertArrayNotHasKey('query_string', $requestData);
        $this->assertStringContainsString(
            '/api/__test/sentry-pii-capture/{manageToken}',
            $requestData['url'] ?? '',
            'the route pattern should replace the interpolated manage token in the URL'
        );

        // C2: no log breadcrumb at all (breadcrumbs.logs is off), so the
        // reminder log line — and the customer phone number in it — never
        // entered the breadcrumb buffer in the first place. (The envelope
        // nests the list under a `values` key: `{"breadcrumbs":{"values":[...]}}`.)
        $breadcrumbs = $payload['breadcrumbs']['values'] ?? [];
        $this->assertNotEmpty($breadcrumbs, 'expected at least some breadcrumbs (e.g. SQL query breadcrumbs) to assert against');
        $logBreadcrumbs = array_filter($breadcrumbs, fn ($b) => str_starts_with($b['category'] ?? '', 'log.'));
        $this->assertCount(0, $logBreadcrumbs, 'no log breadcrumbs should have been captured — breadcrumbs.logs is off');

        // I3: every stack frame of every exception has its `vars` stripped,
        // so the SQL binding (customer email) never appears there either,
        // regardless of the php.ini in effect on whatever box this runs on.
        $frames = $payload['exception']['values'][0]['stacktrace']['frames'] ?? [];
        $this->assertNotEmpty($frames, 'expected at least one stack frame to assert against');

        foreach ($frames as $frame) {
            $this->assertArrayNotHasKey('vars', $frame, sprintf(
                'frame %s::%s still carries vars',
                $frame['module'] ?? '?',
                $frame['function'] ?? '?'
            ));
        }
    }

    /**
     * Fixes review finding I4 specifically: when no route matched — an
     * exception thrown from global middleware, before `Router::dispatch`
     * runs — `$request->route()` is null and there is no route pattern to
     * substitute. The previous version of this hook fell back to
     * `$request->path()`, the literal interpolated path (leaking exactly
     * the manage token it exists to remove) and omitted the leading slash
     * the route branch added. Exercised directly against
     * `SentryBeforeSend::handle()` rather than through a full HTTP request,
     * since reproducing "exception before routing" through the real
     * middleware stack would require faking a failure in global middleware
     * — this isolates the one behaviour that matters: what the hook does
     * with a request that has no bound route.
     */
    public function test_before_send_drops_the_path_entirely_when_no_route_matched(): void
    {
        $secretManageToken = 'super-secret-manage-token-should-not-leak';

        $request = LaravelRequest::create(
            'http://acme.salonhub.test/public/acme/manage/'.$secretManageToken.'?signature=leak',
            'GET'
        );
        // No route bound to this request — simulates an exception thrown
        // before the router ever dispatched, so $request->route() is null.
        app()->instance('request', $request);

        $event = Event::createEvent();
        $event->setRequest([
            'url' => (string) $request->fullUrl(),
            'method' => 'GET',
            'query_string' => 'signature=leak',
        ]);

        $result = SentryBeforeSend::handle($event);
        $requestData = $result->getRequest();

        $this->assertStringNotContainsString($secretManageToken, $requestData['url'] ?? '');
        $this->assertSame('http://acme.salonhub.test/<unrouted>', $requestData['url'] ?? null);
        $this->assertArrayNotHasKey('query_string', $requestData);
    }
}
