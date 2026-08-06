<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            // Null means "still being asked". Stamped means the owner has
            // either finished the wizard or dismissed it for good; the SPA
            // reads it to decide whether to open the wizard on login.
            $table->timestamp('onboarding_completed_at')->nullable()->after('status');
        });

        // Every organization that already exists at this point predates the
        // wizard: it was set up the old way, page by page through the
        // dashboard, and is very likely already taking bookings. Left null,
        // the SPA would read "never onboarded" and divert its owner into a
        // first-run setup wizard for a salon they have been running for
        // months — and a salon whose branch happens to have no address would
        // be walked through the address screen before the wizard let them
        // back out. Stamping created_at says "this one was finished before
        // the wizard existed" without inventing a completion date of today.
        DB::table('organizations')
            ->whereNull('onboarding_completed_at')
            ->update(['onboarding_completed_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('onboarding_completed_at');
        });
    }
};
