<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One payroll run books one salary expense — never two. Two finalize
     * requests racing each other could both pass the application's draft
     * check and both insert, which the P&L would then count twice.
     * PayrollRunController re-reads under a row lock to stop that; this
     * index is the backstop that makes it impossible rather than unlikely.
     *
     * The column is nullable, and both MySQL and SQLite allow repeated NULLs
     * in a unique index, so hand-logged expenses are unaffected.
     */
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->unique('payroll_run_id');
        });
    }

    /**
     * MySQL satisfies the foreign key added one migration earlier with this
     * very index rather than creating its own, so dropping it on its own is
     * refused (errno 1553). Drop the constraint, drop the index, put the
     * constraint back — it then builds the plain index it needs.
     */
    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropForeign(['payroll_run_id']);
            $table->dropUnique(['payroll_run_id']);
            $table->foreign('payroll_run_id')->references('id')->on('payroll_runs')->cascadeOnDelete();
        });
    }
};
