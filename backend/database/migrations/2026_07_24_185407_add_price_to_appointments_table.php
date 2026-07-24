<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The amount owed for this booking, snapshotted from the service price at
     * booking time. Kept on the appointment so revenue and invoices reflect
     * what was quoted, not a menu price that may have changed since.
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->decimal('price', 10, 2)->default(0)->after('end_time');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('price');
        });
    }
};
