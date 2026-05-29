<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Asuransi/TPA Non-BPJS — checklist dokumen wajib per TPA.
 * Contoh: "Surat Rujukan", "Resume Medis", "Kwitansi Asli", dll.
 *
 * Spec: Docs/ARUMED_INSURANCE_TPA_MODULE.md (Migration 3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insurer_document_requirements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('insurer_id')
                ->constrained('insurers')
                ->cascadeOnDelete();

            $table->string('document_name');           // Nama dokumen
            $table->boolean('is_required')->default(true);
            $table->text('notes')->nullable();         // Keterangan format/rangkap
            $table->integer('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index('insurer_id');
            $table->index(['insurer_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insurer_document_requirements');
    }
};
