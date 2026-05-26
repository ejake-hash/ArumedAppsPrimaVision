<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diagnostic_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('diagnostic_order_id')->unique()->constrained('diagnostic_orders')->cascadeOnDelete();
            $table->foreignUuid('performed_by_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->jsonb('expertise_data')->nullable();
            $table->string('attachment_path', 500)->nullable();
            $table->text('notes')->nullable();
            $table->string('result_status', 20)->default('PENDING'); // PENDING / COMPLETED / REVIEWED / APPROVED
            $table->timestamp('uploaded_at')->nullable();
            $table->foreignUuid('reviewed_by_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('result_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diagnostic_results');
    }
};
