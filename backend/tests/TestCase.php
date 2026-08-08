<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Sanctum's guard is a RequestGuard, which caches the resolved user for
     * the guard instance's lifetime rather than per request. Inside a single
     * test method the container (and so the guard) persists across multiple
     * HTTP calls, so calling withToken() a second time for a different user
     * would otherwise silently keep resolving to the first one. Forgetting
     * the cached guards forces a fresh resolution against the new
     * Authorization header on the next request.
     */
    public function withToken(#[\SensitiveParameter] string $token, string $type = 'Bearer'): static
    {
        $this->app['auth']->forgetGuards();

        return parent::withToken($token, $type);
    }
}
