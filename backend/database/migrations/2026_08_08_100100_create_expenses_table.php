<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Everything the salon spends. `payroll_run_id` is set only on the one
     * salary row a finalized payroll run creates: it stops the P&L counting
     * staff pay twice, and the cascade means deleting a run takes its
     * salary expense with it.
     */
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payroll_run_id')->nullable();
            // Who keyed it in. nullOnDelete so removing a staff account never
            // erases the money record they entered.
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category');
            $table->date('expense_date');
            $table->decimal('amount', 10, 2);
            $table->string('note')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'expense_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
