<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How this staff member is paid. Existing staff default to 'none' —
     * the salon has not told us their deal yet, and payroll skips them
     * rather than inventing a number.
     */
    public function up(): void
    {
        Schema::table('staff_profiles', function (Blueprint $table) {
            $table->string('pay_type')->default('none')->after('working_hours_json');
            $table->decimal('monthly_salary', 10, 2)->default(0)->after('pay_type');
            $table->decimal('commission_rate', 5, 2)->default(0)->after('monthly_salary');
        });
    }

    public function down(): void
    {
        Schema::table('staff_profiles', function (Blueprint $table) {
            $table->dropColumn(['pay_type', 'monthly_salary', 'commission_rate']);
        });
    }
};
