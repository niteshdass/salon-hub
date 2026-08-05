<?php

use App\Monitoring\SentryBeforeSend;

/**
 * Sentry Laravel SDK configuration file.
 *
 * @see https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/
 *
 * SalonHub holds customer names, phone numbers, emails, booking history and
 * payment references in one shared database keyed by organization_id, and
 * an error reporter is a data-exfiltration channel by default: the SDK
 * ships `send_default_pii` off, but several other things below are NOT off
 * by default. Found by inspecting `RequestIntegration`, `CacheIntegration`,
 * `EventHandler`, `Frame`/`Stacktrace` and `Options` in vendor/sentry, then
 * verified against a live captured event (see task-13-report.md for the
 * captured evidence, not just this reasoning):
 *
 *   1. `max_request_body_size` defaults to `medium` (10KB) and captures the
 *      parsed POST body REGARDLESS of `send_default_pii` — the OTP code
 *      (customer/auth/verify-code), the login password
 *      (Auth\AuthController::login) and payment-gateway callback payloads
 *      (payment/{tran}/callback/*) would all ride along on any exception
 *      during those requests. Forced to `none` below.
 *   2. `request.url` and `request.query_string` are captured unconditionally
 *      (no `send_default_pii` gate at all) and several public routes carry
 *      an unguessable secret directly in the URL: the booking-manage token
 *      (Public\BookingController::manage/reschedule/cancel, "the token is
 *      unguessable" per its own docblock) in the path, and a signed
 *      email-verification link's `signature`/`expires` in the query string.
 *      `App\Monitoring\SentryBeforeSend` (below) replaces the captured URL
 *      with the route's pattern and drops the query string entirely,
 *      including when no route matched at all; the tenant is still
 *      identifiable from the organization_id/organization_slug tags set in
 *      ResolveTenant/ResolvePublicTenant.
 *   3. The `cache` breadcrumb (and tracing span) default on and record the
 *      raw cache key. This app's rate limiters key the cache entry on the
 *      customer's own email address and, for staff login, the client IP
 *      too (Auth\AuthController::login, Customer\AuthController::requestCode)
 *      — so an unrelated exception later in the same request would carry
 *      that email/IP into Sentry as a breadcrumb. Forced off below, for
 *      both breadcrumbs and tracing.
 *   4. The `logs` breadcrumb defaults on and forwards the full log message
 *      AND context ungated by `send_default_pii` (`EventHandler`). Two real
 *      call sites leak customer PII this way:
 *      `App\Reminders\LogReminderChannel` logs `"[reminder] to={$phone} ::
 *      {$message}"` — the customer's raw phone number, on the channel that
 *      is *active by default* (Twilio unconfigured, exactly what
 *      env.production.example ships) — and `App\Services\BookingNotifier`
 *      logs failed-notification exception messages, which routinely quote
 *      the recipient email/phone, from the public booking POST path. Forced
 *      off below.
 *   5. Stack frame arguments (`frame.vars`, populated from
 *      `debug_backtrace()`) are serialized on every reported exception,
 *      ungated by `send_default_pii` or `sql_bindings` — an ordinary DB
 *      fault puts the raw SQL bindings (customer name/phone/email from any
 *      query built from user input) into `Illuminate\Database\Connection
 *      ::select()`'s frame. `App\Monitoring\SentryBeforeSend` strips `vars`
 *      from every frame of every reported exception, rather than relying on
 *      the `zend.exception_ignore_args` php.ini setting having been set
 *      correctly on whatever box this runs on — see that class's docblock.
 *
 * SQL query bindings on breadcrumbs/spans (as opposed to stack frames,
 * covered by #5) already default OFF in the published config
 * (`sql_bindings` under both `breadcrumbs` and `tracing`) and are left
 * untouched.
 *
 * `before_send` below is a `[ClassName::class, 'method']` array callable,
 * not a closure — a closure here breaks `php artisan config:cache` (see
 * `App\Monitoring\SentryBeforeSend`'s docblock).
 */
return [

    // @see https://docs.sentry.io/concepts/key-terms/dsn-explainer/
    'dsn' => env('SENTRY_LARAVEL_DSN', env('SENTRY_DSN')),

    // @see https://spotlightjs.com/
    // 'spotlight' => env('SENTRY_SPOTLIGHT', false),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#logger
    // 'logger' => Sentry\Logger\DebugFileLogger::class, // By default this will log to `storage_path('logs/sentry.log')`

    // The release version of your application
    // Example with dynamic git hash: trim(exec('git --git-dir ' . base_path('.git') . ' log --pretty="%h" -n1 HEAD'))
    'release' => env('SENTRY_RELEASE'),

    // When left empty or `null` the Laravel environment will be used (usually discovered from `APP_ENV` in your `.env`)
    'environment' => env('SENTRY_ENVIRONMENT'),

    // Override the organization ID used for trace continuation checks.
    'org_id' => env('SENTRY_ORG_ID') === null ? null : (int) env('SENTRY_ORG_ID'),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#sample_rate
    'sample_rate' => env('SENTRY_SAMPLE_RATE') === null ? 1.0 : (float) env('SENTRY_SAMPLE_RATE'),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#traces_sample_rate
    'traces_sample_rate' => env('SENTRY_TRACES_SAMPLE_RATE') === null ? null : (float) env('SENTRY_TRACES_SAMPLE_RATE'),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#profiles_sample_rate
    'profiles_sample_rate' => env('SENTRY_PROFILES_SAMPLE_RATE') === null ? null : (float) env('SENTRY_PROFILES_SAMPLE_RATE'),

    // Only continue incoming traces when the organization IDs are compatible with this SDK instance.
    'strict_trace_continuation' => env('SENTRY_STRICT_TRACE_CONTINUATION', false),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#enable_logs
    'enable_logs' => env('SENTRY_ENABLE_LOGS', false),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#enable_metrics
    'enable_metrics' => env('SENTRY_ENABLE_METRICS', true),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#log_flush_threshold
    'log_flush_threshold' => env('SENTRY_LOG_FLUSH_THRESHOLD') === null ? null : (int) env('SENTRY_LOG_FLUSH_THRESHOLD'),

    // The minimum log level that will be sent to Sentry as logs using the `sentry_logs` logging channel
    'logs_channel_level' => env('SENTRY_LOG_LEVEL', env('SENTRY_LOGS_LEVEL', env('LOG_LEVEL', 'debug'))),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#send_default_pii
    // Hardcoded, not env-driven: this is the primary switch against sending
    // customer IP addresses, cookies and full headers, so no env
    // misconfiguration on the server should be able to flip it on.
    'send_default_pii' => false,

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#max_request_body_size
    // 'medium' (the SDK default) captures up to 10KB of the parsed POST
    // body on every event regardless of `send_default_pii` — see the file
    // docblock above. Hardcoded off for the same reason as `send_default_pii`.
    'max_request_body_size' => 'none',

    // Strips request URL/query-string PII and stack-frame argument PII.
    // A `[class, method]` array, not a closure — see the file docblock
    // above and App\Monitoring\SentryBeforeSend's docblock for why.
    'before_send' => [SentryBeforeSend::class, 'handle'],

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#ignore_exceptions
    // 'ignore_exceptions' => [],

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#ignore_transactions
    'ignore_transactions' => [
        // Ignore Laravel's default health URL
        '/up',
    ],

    // Breadcrumb specific configuration
    'breadcrumbs' => [
        // Capture Laravel logs as breadcrumbs.
        // Hardcoded off, not env-driven: `EventHandler` forwards the full
        // log message AND context ungated by `send_default_pii` —
        // App\Reminders\LogReminderChannel logs the customer's raw phone
        // number, and App\Services\BookingNotifier logs notification
        // failures whose messages routinely quote the recipient address.
        // See the file docblock above.
        'logs' => false,

        // Capture Laravel cache events (hits, writes etc.) as breadcrumbs.
        // Hardcoded off, not env-driven: this app's rate limiters key the
        // cache entry on the customer's own email (and, for staff login,
        // their IP too) — see the file docblock above. The raw key is what
        // this breadcrumb records.
        'cache' => false,

        // Capture Livewire components like routes as breadcrumbs
        'livewire' => env('SENTRY_BREADCRUMBS_LIVEWIRE_ENABLED', true),

        // Capture SQL queries as breadcrumbs
        'sql_queries' => env('SENTRY_BREADCRUMBS_SQL_QUERIES_ENABLED', true),

        // Capture SQL query bindings (parameters) in SQL query breadcrumbs
        'sql_bindings' => env('SENTRY_BREADCRUMBS_SQL_BINDINGS_ENABLED', false),

        // Capture queue job information as breadcrumbs
        'queue_info' => env('SENTRY_BREADCRUMBS_QUEUE_INFO_ENABLED', true),

        // Capture command information as breadcrumbs
        'command_info' => env('SENTRY_BREADCRUMBS_COMMAND_JOBS_ENABLED', true),

        // Capture HTTP client request information as breadcrumbs
        'http_client_requests' => env('SENTRY_BREADCRUMBS_HTTP_CLIENT_REQUESTS_ENABLED', true),

        // Capture send notifications as breadcrumbs
        'notifications' => env('SENTRY_BREADCRUMBS_NOTIFICATIONS_ENABLED', true),
    ],

    // Performance monitoring specific configuration
    'tracing' => [
        // Trace queue jobs as their own transactions (this enables tracing for queue jobs)
        'queue_job_transactions' => env('SENTRY_TRACE_QUEUE_ENABLED', true),

        // Capture queue jobs as spans when executed on the sync driver
        'queue_jobs' => env('SENTRY_TRACE_QUEUE_JOBS_ENABLED', true),

        // Capture SQL queries as spans
        'sql_queries' => env('SENTRY_TRACE_SQL_QUERIES_ENABLED', true),

        // Capture SQL query bindings (parameters) in SQL query spans
        'sql_bindings' => env('SENTRY_TRACE_SQL_BINDINGS_ENABLED', false),

        // Capture where the SQL query originated from on the SQL query spans
        'sql_origin' => env('SENTRY_TRACE_SQL_ORIGIN_ENABLED', true),

        // Define a threshold in milliseconds for SQL queries to resolve their origin
        'sql_origin_threshold_ms' => env('SENTRY_TRACE_SQL_ORIGIN_THRESHOLD_MS', 100),

        // Capture views rendered as spans
        'views' => env('SENTRY_TRACE_VIEWS_ENABLED', true),

        // Capture Livewire components as spans
        'livewire' => env('SENTRY_TRACE_LIVEWIRE_ENABLED', true),

        // Capture HTTP client requests as spans
        'http_client_requests' => env('SENTRY_TRACE_HTTP_CLIENT_REQUESTS_ENABLED', true),

        // Capture Laravel cache events (hits, writes etc.) as spans.
        // Hardcoded off for the same reason as `breadcrumbs.cache` above —
        // left disabled here too so turning tracing on later doesn't
        // silently reopen the same leak.
        'cache' => false,

        // Capture Redis operations as spans (this enables Redis events in Laravel)
        'redis_commands' => env('SENTRY_TRACE_REDIS_COMMANDS', false),

        // Capture where the Redis command originated from on the Redis command spans
        'redis_origin' => env('SENTRY_TRACE_REDIS_ORIGIN_ENABLED', true),

        // Capture send notifications as spans
        'notifications' => env('SENTRY_TRACE_NOTIFICATIONS_ENABLED', true),

        // Enable tracing for requests without a matching route (404's)
        'missing_routes' => env('SENTRY_TRACE_MISSING_ROUTES_ENABLED', false),

        // Configures if the performance trace should continue after the response has been sent to the user until the application terminates
        // This is required to capture any spans that are created after the response has been sent like queue jobs dispatched using `dispatch(...)->afterResponse()` for example
        'continue_after_response' => env('SENTRY_TRACE_CONTINUE_AFTER_RESPONSE', true),

        // Capture AI agent interactions as spans (requires laravel/ai)
        'gen_ai' => env('SENTRY_TRACE_GEN_AI_ENABLED', true),

        // Capture AI invoke_agent spans
        'gen_ai_invoke_agent' => env('SENTRY_TRACE_GEN_AI_INVOKE_AGENT_ENABLED', true),

        // Capture AI chat spans
        'gen_ai_chat' => env('SENTRY_TRACE_GEN_AI_CHAT_ENABLED', true),

        // Capture AI execute_tool spans
        'gen_ai_execute_tool' => env('SENTRY_TRACE_GEN_AI_EXECUTE_TOOL_ENABLED', true),

        // Capture AI embeddings spans
        'gen_ai_embeddings' => env('SENTRY_TRACE_GEN_AI_EMBEDDINGS_ENABLED', true),

        // Enable the tracing integrations supplied by Sentry (recommended)
        'default_integrations' => env('SENTRY_TRACE_DEFAULT_INTEGRATIONS_ENABLED', true),
    ],

];
