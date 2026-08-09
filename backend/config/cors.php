<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    // Absolute origins, comma-separated in CORS_ALLOWED_ORIGINS. The Vite dev
    // server stays in the default so a fresh checkout works with no .env edit.
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173,http://127.0.0.1:5173'))
    ))),

    // Every salon gets <slug>.APP_DOMAIN, so the subdomain space is matched by
    // pattern rather than enumerated. Anchored at both ends and the dot before
    // the apex is escaped, so `glowhub.com.evil.test` cannot match.
    'allowed_origins_patterns' => [
        '#^https://[a-z0-9-]+\.'.preg_quote((string) env('APP_DOMAIN', 'glowhub.com'), '#').'$#i',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
