<?php

use Illuminate\Support\Facades\Route;

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
