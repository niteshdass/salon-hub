<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            // One review per appointment: the unique key guards double submission.
            $table->foreignId('appointment_id')->unique()->constrained()->cascadeOnDelete();
            // Snapshot of who served: kept for a per-staff average, and survives
            // the staff member being deleted.
            $table->foreignId('staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            // Snapshot of the customer's name at submission (public display).
            $table->string('reviewer_name');
            // published (default, live) | hidden (owner-moderated).
            $table->string('status')->default('published');
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
