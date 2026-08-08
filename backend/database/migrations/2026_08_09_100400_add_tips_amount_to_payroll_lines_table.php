<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tips collected against this staff member's completed visits in the
     * month. Paid through in full and deliberately outside `earned_revenue`,
     * so no commission rate is ever applied to money a customer handed
     * directly to the stylist.
     */
    public function up(): void
    {
        Schema::table('payroll_lines', function (Blueprint $table) {
            $table->decimal('tips_amount', 10, 2)->default(0)->after('commission_amount');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_lines', function (Blueprint $table) {
            $table->dropColumn('tips_amount');
        });
    }
};
