<?php

namespace Tests\Feature\Migrations;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 2026_08_05_100100_verify_existing_apex_domains flips is_verified on every
 * unverified primary single-label *.APP_DOMAIN row so salons that registered
 * before host resolution demanded verification keep working.
 *
 * Its down() is a deliberate no-op. That makes one row class dangerous: a
 * platform hostname held by an organization that registered before
 * Organization::RESERVED_SLUGS existed. Verifying it hands that tenant a
 * claim Domain::resolveOrganizationForHost will honour, and there is no
 * rollback. The runbook asks the operator to check first; this pins the
 * behaviour that makes skipping the check survivable.
 */
class ApexDomainBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function organization(string $slug): Organization
    {
        return Organization::create([
            'uuid' => (string) Str::uuid(),
            'name' => ucfirst($slug),
            'slug' => $slug,
            'email' => "owner@{$slug}.test",
            'subscription_plan' => 'free',
            'status' => 'active',
        ]);
    }

    private function unverifiedDomain(Organization $organization, string $host): int
    {
        return DB::table('domains')->insertGetId([
            'organization_id' => $organization->id,
            'domain' => $host,
            'is_primary' => true,
            'is_verified' => false,
            'ssl_enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function runBackfill(): void
    {
        $migration = require database_path('migrations/2026_08_05_100100_verify_existing_apex_domains.php');

        $migration->up();
    }

    public function test_the_backfill_never_verifies_a_reserved_platform_hostname(): void
    {
        $apex = (string) config('app.domain');

        $ids = [];
        foreach (Organization::RESERVED_SLUGS as $slug) {
            // A slug that predates the reserved list — registration refuses
            // these now, but nothing went back and fixed rows written before.
            $ids[$slug] = $this->unverifiedDomain($this->organization('legacy-'.$slug), $slug.'.'.$apex);
        }

        $this->runBackfill();

        foreach ($ids as $slug => $id) {
            $this->assertSame(
                0,
                (int) DB::table('domains')->where('id', $id)->value('is_verified'),
                "{$slug}.{$apex} was handed a verified claim on a platform hostname."
            );
        }
    }

    public function test_the_backfill_still_verifies_an_ordinary_salon_subdomain(): void
    {
        $apex = (string) config('app.domain');

        $id = $this->unverifiedDomain($this->organization('beautyqueen'), 'beautyqueen.'.$apex);

        $this->runBackfill();

        $row = DB::table('domains')->where('id', $id)->first();

        // The whole reason the migration exists: without this the salon 404s
        // on its own subdomain the moment host resolution goes live.
        $this->assertSame(1, (int) $row->is_verified);
        $this->assertSame(1, (int) $row->ssl_enabled);
    }
}
