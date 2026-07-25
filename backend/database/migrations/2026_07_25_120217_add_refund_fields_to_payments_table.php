<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // The gateway's own transaction id, returned on validation and
            // required to request a refund of an online deposit.
            $table->string('bank_tran_id')->nullable()->after('transaction_id');
            // The reference SSLCommerz returns once a refund is accepted.
            $table->string('refund_ref')->nullable()->after('bank_tran_id');
            $table->timestamp('refunded_at')->nullable()->after('refund_ref');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['bank_tran_id', 'refund_ref', 'refunded_at']);
        });
    }
};
