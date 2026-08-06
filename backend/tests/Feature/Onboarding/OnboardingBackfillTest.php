<?php

namespace Tests\Feature\Onboarding;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The one thing about this feature that only happens once, on deploy day,
 * and cannot be re-run: what the migration does to the salons that are
 * already open for business.
 */
class OnboardingBackfillTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_08_06_000001_add_onboarding_completed_at_to_organizations_table.php';

    public function test_a_salon_that_predates_the_wizard_is_not_dragged_through_first_run_setup(): void
    {
        $org = Organization::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Beauty Queen',
            'slug' => 'beautyqueen',
            'email' => 'owner@beautyqueen.test',
            'subscription_plan' => 'free',
            'status' => 'active',
        ]);

        // Stand this row back up as it would have looked the moment before
        // deploy: created long ago, with no such column at all. Rolling the
        // migration back and forward again is the only way to run `up()`
        // against data that already exists, which is the exact situation the
        // backfill is for.
        DB::table('organizations')->where('id', $org->id)->update([
            'created_at' => '2025-01-04 09:30:00',
        ]);
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION]);
        $this->artisan('migrate', ['--path' => self::MIGRATION]);

        $backfilled = $org->fresh()->onboarding_completed_at;

        // Null here means the SPA's router guard reads "never onboarded" and
        // diverts an owner who has been taking bookings for a year into a
        // first-run setup wizard for a salon they already run.
        $this->assertNotNull($backfilled);
        // And it is stamped from created_at, not from now(): the salon was
        // set up long before the wizard existed, and this column answers
        // "when did they stop being new".
        $this->assertSame('2025-01-04 09:30:00', $backfilled->format('Y-m-d H:i:s'));
    }
}
