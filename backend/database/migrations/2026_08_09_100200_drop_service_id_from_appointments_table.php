<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A visit's services now live in appointment_services. The backfill in
     * 2026_08_09_100100 has already copied every value out of this column, so
     * dropping it removes the second source of truth rather than any data.
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropForeign(['service_id']);
            $table->dropColumn('service_id');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('service_id')->nullable()->after('staff_id')
                ->constrained('services')->cascadeOnDelete();
        });
    }
};
