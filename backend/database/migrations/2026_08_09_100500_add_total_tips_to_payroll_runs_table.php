<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The header's share of what the lines carry in tips_amount. Stored
     * rather than derived so the run's totals stay a matched set — the same
     * treatment total_salary and total_commission already get — and so
     * finalize() can read it off the row it just locked instead of a second
     * query against the lines.
     */
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->decimal('total_tips', 10, 2)->default(0)->after('total_commission');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropColumn('total_tips');
        });
    }
};
