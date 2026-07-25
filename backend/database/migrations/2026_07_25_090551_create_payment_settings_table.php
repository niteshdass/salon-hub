<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-org public-booking payment configuration: the deposit a customer
     * must put down to book, and how they pay it. One row per organization.
     *
     * `gateway` + `credentials` are reserved for the online gateway
     * (SSLCommerz) phase; manual bank/wallet transfer works without them.
     */
    public function up(): void
    {
        Schema::create('payment_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();

            // Deposit policy: none | percent | fixed. Value is a percentage
            // (0-100) for percent, or a currency amount for fixed.
            $table->string('deposit_type')->default('none');
            $table->decimal('deposit_value', 10, 2)->default(0);

            // Manual transfer: the customer sends money out-of-band and quotes
            // a transaction reference, which the salon verifies by hand.
            $table->boolean('manual_enabled')->default(false);
            $table->string('manual_account_number')->nullable();
            $table->text('manual_instructions')->nullable();

            // Online gateway (phase 2). Credentials encrypted at rest.
            $table->string('gateway')->default('none');
            $table->text('credentials')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_settings');
    }
};
