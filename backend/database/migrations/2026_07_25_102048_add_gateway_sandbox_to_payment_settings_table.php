<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payment_settings', function (Blueprint $table) {
            // Sandbox vs live is configuration, not a secret — stored plainly
            // beside the encrypted gateway credentials. Default to sandbox so a
            // half-configured gateway never charges a real card.
            $table->boolean('gateway_sandbox')->default(true)->after('gateway');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_settings', function (Blueprint $table) {
            $table->dropColumn('gateway_sandbox');
        });
    }
};
