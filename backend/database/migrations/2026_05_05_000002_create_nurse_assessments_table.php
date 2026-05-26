<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nurse_assessments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->unique()->constrained('visits')->cascadeOnDelete();
            $table->foreignUuid('assessed_by_id')->nullable()->constrained('employees')->nullOnDelete();

            // TTV
            $table->integer('td_sistol')->nullable();
            $table->integer('td_diastol')->nullable();
            $table->integer('nadi')->nullable();
            $table->decimal('suhu', 4, 1)->nullable();
            $table->integer('respirasi')->nullable();
            $table->decimal('spo2', 5, 2)->nullable();
            $table->decimal('kgd', 6, 2)->nullable();

            // Antropometri
            $table->decimal('berat_badan', 5, 2)->nullable();
            $table->decimal('tinggi_badan', 5, 2)->nullable();
            $table->decimal('bmi', 5, 2)->nullable();

            // Alergi & Keluhan
            $table->boolean('has_allergy')->default(false);
            $table->text('allergy_detail')->nullable();
            $table->text('chief_complaint')->nullable();
            $table->text('assessment_notes')->nullable();

            // Finalisasi
            $table->boolean('is_finalized')->default(false);
            $table->timestamp('finalized_at')->nullable();
            $table->foreignUuid('finalized_by_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nurse_assessments');
    }
};
