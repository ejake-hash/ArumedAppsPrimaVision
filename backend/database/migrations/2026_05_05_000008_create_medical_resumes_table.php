<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_resumes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->unique()->constrained('visits')->cascadeOnDelete();
            $table->foreignUuid('doctor_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->text('resume_s')->nullable(); // S: Anamnese
            $table->text('resume_o')->nullable(); // O: Data refraksionis (auto-populate)
            $table->text('resume_a')->nullable(); // A: ICD-10
            $table->text('resume_p')->nullable(); // P: ICD-9 + planning
            $table->jsonb('penunjang_results')->nullable(); // [{test_type, result, date}]
            $table->boolean('is_editable')->default(true);
            $table->boolean('is_finalized')->default(false);
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_resumes');
    }
};
