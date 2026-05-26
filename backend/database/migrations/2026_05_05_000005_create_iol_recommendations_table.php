<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('iol_recommendations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->constrained('visits')->cascadeOnDelete();
            // diagnostic_results is created in Batch 7 — no constrained()
            $table->uuid('diagnostic_result_id')->nullable();
            $table->string('eye_side', 5); // OD / OS
            $table->decimal('recommended_power', 5, 2)->nullable();
            $table->string('iol_type', 20)->nullable(); // MONOFOCAL / MULTIFOCAL / TORIC / TRIFOCAL / EDOF / PHAKIC
            $table->string('brand')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_approved')->default(false);
            $table->foreignUuid('approved_by_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('visit_id');
            $table->index('eye_side');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iol_recommendations');
    }
};
