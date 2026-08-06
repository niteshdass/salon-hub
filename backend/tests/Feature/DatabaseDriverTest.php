<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Proves the suite ran on the engine CI believes it ran on.
 *
 * The backend-mysql job asserts the *server* has foreign_key_checks on, but
 * nothing asserted the *suite* actually connected to that server. The job
 * relies on plain env vars beating phpunit.xml's `<env>` elements, which holds
 * only because those elements lack force="true". Add force="true" to
 * DB_CONNECTION one day and the MySQL job silently reverts to SQLite, stays
 * green, and proves nothing it did not already prove — a false negative that
 * looks exactly like a passing build.
 *
 * EXPECT_DB_DRIVER is set by each CI job and is deliberately NOT in
 * phpunit.xml, so no change to that file can make this assertion agree with a
 * connection it did not get. Unset (a local run), there is nothing to check
 * against and the test says so rather than guessing.
 */
class DatabaseDriverTest extends TestCase
{
    public function test_the_suite_connected_via_the_driver_the_environment_asked_for(): void
    {
        $expected = getenv('EXPECT_DB_DRIVER');

        if ($expected === false || $expected === '') {
            $this->markTestSkipped(
                'EXPECT_DB_DRIVER is unset, so there is no declared expectation to check. '
                .'CI sets it per job; a local run may legitimately use any driver.'
            );
        }

        $this->assertSame(
            $expected,
            DB::connection()->getDriverName(),
            'The suite connected to a different database engine than this run was set up to exercise.'
        );
    }
}
