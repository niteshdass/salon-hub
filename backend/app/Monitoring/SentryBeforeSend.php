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
        self::redactQueryExceptionBindings($event);

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
     * the message itself carries it as plain text. Redacted here by
     * truncating at the `" (Connection: ..."` suffix Laravel always
     * appends, which is exactly where the interpolated SQL begins —
     * leaving the underlying driver error message (which does not contain
     * bound values) intact and useful.
     */
    private static function redactQueryExceptionBindings(Event $event): void
    {
        $exceptions = $event->getExceptions();

        foreach ($exceptions as $exceptionDataBag) {
            if (! is_a($exceptionDataBag->getType(), QueryException::class, true)) {
                continue;
            }

            $redacted = preg_replace('/ \(Connection: .*/s', '', $exceptionDataBag->getValue());

            if ($redacted !== null) {
                $exceptionDataBag->setValue($redacted);
            }
        }

        $event->setExceptions($exceptions);
    }
}
