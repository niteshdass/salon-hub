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
        // array (or dropped trim/filter step) fails this test. .env.example
        // now sets CORS_ALLOWED_ORIGINS (Task 8), which Dotenv loads into
        // $_ENV/$_SERVER on boot; those adapters outrank the putenv adapter
        // in Laravel's env repository, so they must be cleared too or the
        // override below is silently ignored.
        $originalEnv = $_ENV['CORS_ALLOWED_ORIGINS'] ?? null;
        $originalServer = $_SERVER['CORS_ALLOWED_ORIGINS'] ?? null;
        unset($_ENV['CORS_ALLOWED_ORIGINS'], $_SERVER['CORS_ALLOWED_ORIGINS']);
        putenv('CORS_ALLOWED_ORIGINS=https://a.test, https://b.test, ,');

        try {
            $cors = require config_path('cors.php');
        } finally {
            putenv('CORS_ALLOWED_ORIGINS');
            if ($originalEnv === null) {
                unset($_ENV['CORS_ALLOWED_ORIGINS']);
            } else {
                $_ENV['CORS_ALLOWED_ORIGINS'] = $originalEnv;
            }
            if ($originalServer === null) {
                unset($_SERVER['CORS_ALLOWED_ORIGINS']);
            } else {
                $_SERVER['CORS_ALLOWED_ORIGINS'] = $originalServer;
            }
        }

        $this->assertSame(['https://a.test', 'https://b.test'], $cors['allowed_origins']);
    }

    public function test_app_domain_env_drives_the_subdomain_pattern(): void
    {
        // Same approach for APP_DOMAIN: a non-default apex must actually
        // reach the built pattern, and only that apex, not the inline default.
        // .env.example now sets APP_DOMAIN (Task 8); see the comment in
        // test_cors_allowed_origins_env_is_parsed_trimmed_and_filtered above
        // for why $_ENV/$_SERVER must be cleared too.
        $originalEnv = $_ENV['APP_DOMAIN'] ?? null;
        $originalServer = $_SERVER['APP_DOMAIN'] ?? null;
        unset($_ENV['APP_DOMAIN'], $_SERVER['APP_DOMAIN']);
        putenv('APP_DOMAIN=example.org');

        try {
            $cors = require config_path('cors.php');
        } finally {
            putenv('APP_DOMAIN');
            if ($originalEnv === null) {
                unset($_ENV['APP_DOMAIN']);
            } else {
                $_ENV['APP_DOMAIN'] = $originalEnv;
            }
            if ($originalServer === null) {
                unset($_SERVER['APP_DOMAIN']);
            } else {
                $_SERVER['APP_DOMAIN'] = $originalServer;
            }
        }

        $pattern = $cors['allowed_origins_patterns'][0];

        $this->assertStringContainsString('example\.org', $pattern);
        $this->assertSame(1, preg_match($pattern, 'https://beauty-queen.example.org'));
        $this->assertSame(0, preg_match($pattern, 'https://beauty-queen.salonhub.com'));
    }
}
