<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surgery_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->constrained('visits')->cascadeOnDelete();
            $table->foreignUuid('surgery_schedule_id')->nullable()->constrained('surgery_schedules')->nullOnDelete();
            $table->foreignUuid('requested_by_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('status', 20)->default('REQUESTED'); // REQUESTED / SENT / RECEIVED
            $table->text('notes')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('visit_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surgery_requests');
    }
};
