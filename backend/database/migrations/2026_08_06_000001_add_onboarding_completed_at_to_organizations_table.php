<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('onboarding_completed_at');
        });
    }
};
