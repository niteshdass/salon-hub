<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Add a per-appointment public token so a customer can manage their
     * booking (view / reschedule / cancel) from a shareable link without
     * authenticating.
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->uuid('public_token')->nullable()->unique()->after('id');
        });

        // Backfill existing rows so every appointment is manageable.
        DB::table('appointments')->whereNull('public_token')->orderBy('id')
            ->select('id')->get()
            ->each(function ($row) {
                DB::table('appointments')
                    ->where('id', $row->id)
                    ->update(['public_token' => (string) Str::uuid()]);
            });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropUnique('appointments_public_token_unique');
            $table->dropColumn('public_token');
        });
    }
};
