<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_examinations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->unique()->constrained('visits')->cascadeOnDelete();
            $table->foreignUuid('doctor_id')->nullable()->constrained('employees')->nullOnDelete();

            // Tab 2 — Anamnese
            $table->text('anamnese')->nullable();

            // Segmen Anterior OD (dropdown: Normal / Tidak Normal / Tidak Dapat Dinilai)
            $table->string('sa_kornea_od', 50)->nullable();
            $table->string('sa_coa_od', 50)->nullable();
            $table->string('sa_iris_od', 50)->nullable();
            $table->string('sa_pupil_od', 50)->nullable();
            $table->string('sa_lensa_od', 50)->nullable();

            // Segmen Anterior OS
            $table->string('sa_kornea_os', 50)->nullable();
            $table->string('sa_coa_os', 50)->nullable();
            $table->string('sa_iris_os', 50)->nullable();
            $table->string('sa_pupil_os', 50)->nullable();
            $table->string('sa_lensa_os', 50)->nullable();

            // Segmen Posterior OD
            $table->string('sp_papil_od', 50)->nullable();
            $table->string('sp_macula_od', 50)->nullable();
            $table->string('sp_retina_od', 50)->nullable();
            $table->string('sp_vitreous_od', 50)->nullable();

            // Segmen Posterior OS
            $table->string('sp_papil_os', 50)->nullable();
            $table->string('sp_macula_os', 50)->nullable();
            $table->string('sp_retina_os', 50)->nullable();
            $table->string('sp_vitreous_os', 50)->nullable();

            $table->text('slitlamp_notes')->nullable();

            // Tab 4 — SOAP & Planning
            $table->text('soap_subjective')->nullable();
            $table->text('soap_objective')->nullable();
            $table->text('soap_assessment')->nullable();
            $table->text('soap_plan')->nullable();

            $table->string('diagnosis_utama', 10)->nullable(); // ICD-10
            $table->jsonb('diagnosis_sekunder')->nullable();   // Array ICD-10
            $table->jsonb('tindakan_codes')->nullable();       // Array ICD-9 CM
            $table->string('planning', 50)->nullable();        // PULANG_BEROBAT_JALAN / BEDAH / RUJUK

            $table->foreignUuid('surgery_package_id')->nullable()->constrained('surgery_packages')->nullOnDelete();
            $table->foreignUuid('surgery_schedule_id')->nullable()->constrained('surgery_schedules')->nullOnDelete();
            // medical_resumes is created later in this batch — no constrained()
            $table->uuid('medical_resume_id')->nullable();

            // Digital signature
            $table->boolean('is_finalized')->default(false);
            $table->timestamp('finalized_at')->nullable();
            $table->string('digital_signature', 500)->nullable();
            $table->timestamp('signature_timestamp')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('planning');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_examinations');
    }
};
