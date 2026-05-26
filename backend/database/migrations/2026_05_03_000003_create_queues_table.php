<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queues', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->constrained('visits')->cascadeOnDelete();
            $table->string('station', 50); // ADMISI / TRIASE / REFRAKSIONIS / DOKTER / BEDAH / FARMASI / KASIR
            $table->char('queue_prefix', 1); // A / T / R / D / B / F / K
            $table->integer('queue_sequence');
            $table->string('queue_number', 20); // e.g. A-001
            $table->string('status', 50)->default('WAITING'); // WAITING / CALLED / IN_PROGRESS / COMPLETED / CANCELLED
            $table->timestamp('called_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['station', 'status']);
            $table->index(['station', 'created_at']); // for daily sequence reset
            $table->index('queue_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queues');
    }
};
