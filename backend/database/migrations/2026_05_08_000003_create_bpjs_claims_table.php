<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bpjs_claims', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->constrained('visits')->cascadeOnDelete();
            $table->string('no_sep', 50)->unique();
            $table->string('patient_nik', 16)->nullable();
            $table->string('diagnosis_utama', 10)->nullable();
            $table->jsonb('diagnosis_sekunder')->nullable();
            $table->jsonb('procedure_codes')->nullable();
            $table->string('inacbgs_kode', 20)->nullable();
            $table->decimal('inacbgs_tarif', 12, 2)->nullable();
            $table->jsonb('lupis_data')->nullable();
            $table->string('status', 50)->default('DRAFT'); // DRAFT / REVIEW / VERIFIED / SUBMITTED / SELESAI / DITOLAK
            $table->string('bpjs_status', 20)->nullable(); // PENDING / PROSES / SELESAI / DITOLAK
            $table->jsonb('bpjs_response')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('patient_nik');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bpjs_claims');
    }
};
