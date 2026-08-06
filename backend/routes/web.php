<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
 * Deep health check. The framework's own /up (registered in bootstrap/app.php)
 * is registered outside the web group and only renders a static view: it
 * answers 200 with no APP_KEY, an unreadable .env, no config cache and no
 * database, so it says "live" on a server where every real request 500s.
 *
 * This one is inside the web group and touches the database, so it fails when
 * the app is actually unable to serve:
 *   - .env is chmod 600 deploy:deploy while php-fpm runs as www-data, so the
 *     app hard-depends on bootstrap/cache/config.php. If that file is missing
 *     or stale, the encrypt-cookies/session middleware raises a missing
 *     APP_KEY before this closure runs and the check returns 500.
 *   - Wrong DB credentials, a stopped MySQL, or a socket the www-data user
 *     cannot reach return 503 here.
 *   - Being in the web group also means the session store must answer
 *     (production runs SESSION_DRIVER=redis), so a dead Redis surfaces as a
 *     500 rather than as a green light. That is intended: no session store,
 *     no working dashboard.
 *
 * Throttled because it opens a database connection per request. Its body is
 * fixed text: nothing about the failure reaches the caller, only the log and
 * Sentry.
 */
Route::get('/up/db', function () {
    try {
        DB::connection()->select('select 1');
    } catch (Throwable $e) {
        report($e);

        return response('DATABASE UNAVAILABLE', 503)
            ->header('Content-Type', 'text/plain');
    }

    return response('OK', 200)->header('Content-Type', 'text/plain');
})->middleware('throttle:60,1');

/*
 * Everything that is not an API call, an uploaded file or the health check
 * is a client-side route: hand back the SPA shell and let vue-router decide.
 *
 * A plain Route::fallback() is too greedy — it also swallows unmatched
 * /api/* requests, turning their JSON 404 into an HTML SPA shell with a 200.
 * Scoping an explicit wildcard route with a negative-lookahead regex keeps
 * /api, /storage and the /up health check out of its reach.
 */
Route::get('/{any}', function () {
    return view('app');
})->where('any', '^(?!api|storage|up).*$');
