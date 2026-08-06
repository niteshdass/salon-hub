<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * business_hours was never written to by any code path — opening hours
     * live on branches.opening_hours_json, which is what both the booking
     * engine and the public site read. Drop the empty table rather than
     * leave a second, always-stale source of truth.
     */
    public function up(): void
    {
        Schema::dropIfExists('business_hours');
    }

    public function down(): void
    {
        Schema::create('business_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->time('open_time')->nullable();
            $table->time('close_time')->nullable();
            $table->boolean('is_closed')->default(false);
        });
    }
};
