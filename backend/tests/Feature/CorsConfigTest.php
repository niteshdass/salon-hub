<?php

namespace Tests\Feature;

use Tests\TestCase;

class CorsConfigTest extends TestCase
{
    public function test_allowed_origins_come_from_env_as_a_list(): void
    {
        // Each test boots a fresh application, so this already reads
        // config/cors.php as resolved from env for this test run.
        $origins = config('cors.allowed_origins');

        $this->assertIsArray($origins);
        // Dev origins remain the default so `npm run dev` keeps working.
        $this->assertContains('http://localhost:5173', $origins);
    }

    public function test_a_salon_subdomain_is_matched_by_pattern(): void
    {
        $patterns = config('cors.allowed_origins_patterns');

        $this->assertNotEmpty($patterns, 'No subdomain pattern configured.');

        $matched = collect($patterns)->contains(
            fn (string $pattern) => preg_match($pattern, 'https://beauty-queen.salonhub.com') === 1
        );

        $this->assertTrue($matched, 'A salon subdomain origin is not allowed by any pattern.');
    }

    public function test_a_lookalike_domain_is_not_matched(): void
    {
        $patterns = config('cors.allowed_origins_patterns');

        $matched = collect($patterns)->contains(
            fn (string $pattern) => preg_match($pattern, 'https://salonhub.com.evil.test') === 1
        );

        $this->assertFalse($matched, 'A lookalike domain must not be allowed.');
    }

    public function test_a_multi_label_subdomain_is_not_matched(): void
    {
        // Salon slugs are minted as a single DNS label (<slug>.APP_DOMAIN, see
        // Task 12). There is no product reason to trust a nested subdomain, so
        // the pattern deliberately excludes hosts with more than one label
        // ahead of the apex.
        $patterns = config('cors.allowed_origins_patterns');

        $matched = collect($patterns)->contains(
            fn (string $pattern) => preg_match($pattern, 'https://a.b.salonhub.com') === 1
        );

        $this->assertFalse($matched, 'A multi-label subdomain must not be allowed.');
    }

    public function test_cors_allowed_origins_env_is_parsed_trimmed_and_filtered(): void
    {
        // Bypass the config repository entirely and evaluate the config file
        // fresh against a real env value, so a regression to a hardcoded
        // array (or dropped trim/filter step) fails this test.
        putenv('CORS_ALLOWED_ORIGINS=https://a.test, https://b.test, ,');

        try {
            $cors = require config_path('cors.php');
        } finally {
            putenv('CORS_ALLOWED_ORIGINS');
        }

        $this->assertSame(['https://a.test', 'https://b.test'], $cors['allowed_origins']);
    }

    public function test_app_domain_env_drives_the_subdomain_pattern(): void
    {
        // Same approach for APP_DOMAIN: a non-default apex must actually
        // reach the built pattern, and only that apex, not the inline default.
        putenv('APP_DOMAIN=example.org');

        try {
            $cors = require config_path('cors.php');
        } finally {
            putenv('APP_DOMAIN');
        }

        $pattern = $cors['allowed_origins_patterns'][0];

        $this->assertStringContainsString('example\.org', $pattern);
        $this->assertSame(1, preg_match($pattern, 'https://beauty-queen.example.org'));
        $this->assertSame(0, preg_match($pattern, 'https://beauty-queen.salonhub.com'));
    }
}
