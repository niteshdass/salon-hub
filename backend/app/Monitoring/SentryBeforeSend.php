<?php

namespace App\Monitoring;

use Illuminate\Database\QueryException;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\Stacktrace;

/**
 * Sentry's `before_send` hook for this app.
 *
 * Referenced from config/sentry.php as `[self::class, 'handle']` — a plain
 * two-string array callable — rather than as an inline closure.
 *
 * Why (fixes review finding C1): a Closure stored in the `config()` tree
 * makes `php artisan config:cache` fail outright:
 *
 *   LogicException: Your configuration files could not be serialized
 *   because the value at "sentry.before_send" is non-serializable.
 *
 * `ConfigCacheCommand::handle()` builds the cache file with `var_export()`
 * on the whole config array, and closures cannot be `var_export`ed.
 * `deploy.sh` runs `config:cache` unconditionally, after `migrate --force`
 * has already run, under `set -euo pipefail` — so a closure here would abort
 * every deploy after this file was introduced, mid-deploy, on a schema that
 * no longer matches the running code. An array of two strings
 * (`[ClassName::class, 'method']`) is a normal PHP `callable` (which is all
 * `Sentry\Options` requires of `before_send`) and is trivially
 * `var_export`-able, so it survives `config:cache` unchanged.
 */
final class SentryBeforeSend
{
    public static function handle(Event $event, ?EventHint $hint = null): Event
    {
        self::scrubRequest($event);
        self::stripStackFrameVars($event);
        self::redactDatabaseExceptionMessages($event);

        return $event;
    }

    /**
     * Strips the two things about the captured request that
     * `send_default_pii` does NOT gate: the literal URL (which can carry an
     * unguessable booking-manage token in its path) and the query string
     * (which can carry a signed email-verification link's signature). See
     * config/sentry.php's docblock for the full PII analysis.
     *
     * Fixes review finding I4: when no route matched (e.g. an exception
     * thrown from global middleware, before the router dispatches), the
     * previous version of this hook fell back to `$request->path()` — the
     * literal interpolated path this hook exists to remove — and built a
     * URL missing the leading slash the route branch added elsewhere in the
     * same line. There is no route pattern to fall back to in that case, so
     * the path is dropped entirely (a fixed `/<unrouted>` placeholder)
     * rather than guessed at.
     */
    private static function scrubRequest(Event $event): void
    {
        $requestData = $event->getRequest();

        if (empty($requestData)) {
            return;
        }

        $request = request();

        if ($request !== null && isset($requestData['url'])) {
            $route = $request->route();

            $requestData['url'] = $route
                ? $request->getSchemeAndHttpHost().'/'.ltrim($route->uri(), '/')
                : $request->getSchemeAndHttpHost().'/<unrouted>';
        }

        unset($requestData['query_string']);

        $event->setRequest($requestData);
    }

    /**
     * Fixes review finding I3: the SDK serializes `debug_backtrace()`
     * arguments into `frame.vars` on every stack frame of every reported
     * exception, completely ungated by `send_default_pii` or
     * `sql_bindings`. For an ordinary DB fault this includes the raw SQL
     * bindings passed to `Illuminate\Database\Connection::select()` —
     * customer name, phone and email on any query built from user input.
     *
     * Whether the SDK even has arguments to serialize depends on the
     * `zend.exception_ignore_args` php.ini setting, which nothing in this
     * repository sets or asserts, and which a container base image, a
     * custom php.ini, or a future package upgrade could silently flip back
     * to PHP's own compiled default (off, i.e. args ARE captured). Stripped
     * here instead of relying on ini configuration, so the guarantee holds
     * regardless of the php.ini in effect on whatever box this runs on.
     */
    private static function stripStackFrameVars(Event $event): void
    {
        foreach ($event->getExceptions() as $exceptionDataBag) {
            $stacktrace = $exceptionDataBag->getStacktrace();

            if ($stacktrace !== null) {
                self::stripFrames($stacktrace);
            }
        }

        $stacktrace = $event->getStacktrace();

        if ($stacktrace !== null) {
            self::stripFrames($stacktrace);
        }
    }

    private static function stripFrames(Stacktrace $stacktrace): void
    {
        foreach ($stacktrace->getFrames() as $frame) {
            $frame->setVars([]);
        }
    }

    /**
     * Found while building the runtime proof for this task, not named by
     * the original review findings: `Illuminate\Database\QueryException`
     * (and subclasses, e.g. `UniqueConstraintViolationException`) bake the
     * bound values directly into the *exception message text* —
     * `QueryException::formatMessage()` does `Str::replaceArray('?',
     * $bindings, $sql)` and appends the result — so an ordinary DB fault on
     * a query built from customer input (email, phone, name) puts that
     * value in `exception.values[].value`, independent of stack-frame
     * `vars` entirely. Stripping frame vars (above) does not touch this;
     * the message itself carries it as plain text.
     *
     * CORRECTION (fix round 2): an earlier version of this method only
     * truncated the `" (Connection: ..."` suffix Laravel appends, on the
     * claim that "the underlying driver error message does not contain
     * bound values." That claim is false on MySQL (this app's production
     * driver — see `docs/deploy/env.production.example`): MySQL 8's own
     * PDO error text echoes the offending value directly, e.g.
     *
     *   SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate
     *   entry 'victim@varsalon.test' for key 'customer_accounts_email_unique'
     *
     *   SQLSTATE[22007]: Invalid datetime format: 1292 Incorrect datetime
     *   value: '+8801711223344' for column 'starts_at' at row 1
     *
     * both fully reachable in shipped code (a customer-email unique-index
     * race on register; MySQL strict-mode value rejection on the public
     * booking POST) and untouched by the old truncation, which only ever
     * looked at the Laravel-appended suffix, never the driver's own text.
     * SQLite (the test driver) never echoes values this way, which is why
     * the original test passed while the production case was open.
     *
     * Redacting driver-specific quoting/escaping conventions one at a time
     * is a losing game — a different driver, a PDO minor version, or a
     * error-message locale changes the shape without warning. Instead this
     * keeps only a short, explicitly-safe prefix (the SQLSTATE code, the
     * driver's error class text, and its numeric error code — standard PDO
     * fields that never carry query data) and replaces everything else
     * with a fixed marker. An unrecognised message shape (no `SQLSTATE[`
     * prefix at all) is redacted completely rather than passed through, so
     * the failure mode of "a driver I didn't think of" is total redaction,
     * not a silent leak.
     */
    private static function redactDatabaseExceptionMessages(Event $event): void
    {
        $exceptions = $event->getExceptions();

        foreach ($exceptions as $exceptionDataBag) {
            if (! is_a($exceptionDataBag->getType(), QueryException::class, true)) {
                continue;
            }

            $exceptionDataBag->setValue(self::redactDriverMessage($exceptionDataBag->getValue()));
        }

        $event->setExceptions($exceptions);
    }

    private static function redactDriverMessage(?string $message): string
    {
        $fallback = '[message redacted by SentryBeforeSend — unrecognised shape, may contain user-supplied data]';

        if ($message === null || $message === '') {
            return $fallback;
        }

        // The PDO-standard prefix every driver this app supports (mysql,
        // pgsql, sqlite) produces: "SQLSTATE[<state>]: <class text>:
        // <driver code> <driver message...>". Only the first three pieces
        // are captured — none of them are query data — everything after is
        // discarded regardless of shape.
        if (! preg_match('/^SQLSTATE\[(?<state>[^\]]+)\]:\s*(?<class>[^:]*?):\s*(?<code>-?\d+)/', $message, $m)) {
            return $fallback;
        }

        $class = trim($m['class']);

        return sprintf(
            'SQLSTATE[%s]: %s%s [message redacted by SentryBeforeSend — may contain user-supplied data]',
            $m['state'],
            $class !== '' ? $class.': ' : '',
            $m['code']
        );
    }
}
