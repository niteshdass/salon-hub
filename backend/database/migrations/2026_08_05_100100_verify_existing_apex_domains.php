<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill for the subdomain going live.
 *
 * Host resolution now answers only on VERIFIED Domain rows
 * (Domain::resolveOrganizationForHost), and registration mints new rows
 * verified. Rows written before this change carry is_verified = false, so
 * without this backfill every salon that registered earlier would silently
 * 404 on its own subdomain the moment the feature shipped.
 *
 * Scoped to the primary row under our own apex, and only that:
 * `<slug>.APP_DOMAIN` is served by a wildcard vhost and a wildcard certificate
 * we control, so there is nothing about it left to verify. A custom domain
 * (v1.2) names a host we do NOT control and must stay unverified until it is
 * checked — this migration deliberately cannot reach one.
 */
return new class extends Migration
{
    public function up(): void
    {
        $apex = addcslashes((string) config('app.domain'), '%_\\');

        DB::table('domains')
            ->where('is_verified', false)
            // Exactly the row registration mints, and nothing else: the
            // organization's own primary host. A non-primary row is a domain
            // somebody added later, and the only flow that adds one is the
            // custom-domain flow whose whole purpose is to verify it.
            ->where('is_primary', true)
            ->where('domain', 'like', '%.'.$apex)
            // Single label only. `<slug>.APP_DOMAIN` is what the wildcard
            // vhost and wildcard certificate cover; a deeper name is not
            // something this migration knows we serve.
            ->where('domain', 'not like', '%.%.'.$apex)
            ->update([
                'is_verified' => true,
                'ssl_enabled' => true,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Not reversed. Rolling back would take working salon subdomains
        // offline, and the rows cannot be told apart from ones registered
        // after this migration ran.
    }
};
