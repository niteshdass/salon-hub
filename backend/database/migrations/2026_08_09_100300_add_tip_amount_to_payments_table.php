<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A tip taken alongside this payment. Kept separate from `amount` because
     * `amount` is what settles the booking's balance and a tip settles
     * nothing — it is money for the stylist that happens to be handed over at
     * the same counter, at the same moment.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('tip_amount', 10, 2)->default(0)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('tip_amount');
        });
    }
};
