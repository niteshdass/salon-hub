<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One service on one visit. name/price/duration are snapshots taken at
     * booking time for the same reason appointments.price is: the invoice must
     * show what was quoted, not a menu that has moved since. Because the
     * snapshot stands on its own, service_id is nullOnDelete — losing the menu
     * row must never cost the salon its visit history.
     *
     * No organization_id and no tenant scope: a line is only ever reached
     * through its appointment, which is scoped.
     */
    public function up(): void
    {
        Schema::create('appointment_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->string('name');
            $table->decimal('price', 10, 2)->default(0);
            $table->unsignedInteger('duration')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index('appointment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_services');
    }
};
