<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The framework's /up answers 200 without touching anything real, so the
 * runbook needs a second gate that fails when the app cannot actually serve.
 * These tests pin the two behaviours the runbook relies on: OK when the
 * database answers, 503 when it does not.
 */
class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_database_health_check_is_ok_when_the_database_answers(): void
    {
        $this->get('/up/db')
            ->assertOk()
            ->assertSee('OK');
    }

    public function test_the_database_health_check_fails_when_the_database_does_not_answer(): void
    {
        // Point the default connection at a database that cannot be opened,
        // rather than mocking, so the closure's real connection attempt is
        // what fails. Restored before the test returns: RefreshDatabase rolls
        // its transaction back on the *default* connection during teardown.
        $original = config('database.default');

        config([
            'database.connections.unreachable' => [
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'port' => '1',
                'database' => 'nope',
                'username' => 'nope',
                'password' => 'nope',
            ],
            'database.default' => 'unreachable',
        ]);

        try {
            $this->get('/up/db')
                ->assertStatus(503)
                ->assertSee('DATABASE UNAVAILABLE');
        } finally {
            config(['database.default' => $original]);
            DB::purge('unreachable');
        }
    }

    public function test_the_shallow_health_check_still_answers(): void
    {
        $this->get('/up')->assertOk();
    }
}
