<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What one staff member was paid for one month. The pay rule is
     * snapshotted, so a later raise never rewrites a past month, and
     * `staff_name` is kept so deleting a staff account does not erase the
     * record of what they were paid.
     *
     * `earned_revenue` and `bookings` are computed and never edited: when an
     * owner overrides an amount, the reality it departs from stays visible.
     */
    public function up(): void
    {
        Schema::create('payroll_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('staff_name');
            $table->string('pay_type');
            $table->decimal('commission_rate', 5, 2)->default(0);
            $table->decimal('monthly_salary', 10, 2)->default(0);
            $table->decimal('earned_revenue', 10, 2)->default(0);
            $table->unsignedInteger('bookings')->default(0);
            $table->decimal('salary_amount', 10, 2)->default(0);
            $table->decimal('commission_amount', 10, 2)->default(0);
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->timestamps();
            $table->index('payroll_run_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_lines');
    }
};
