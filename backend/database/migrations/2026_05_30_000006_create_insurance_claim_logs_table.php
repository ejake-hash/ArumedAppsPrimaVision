<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Asuransi/TPA Non-BPJS — audit trail status klaim.
 * Immutable: tidak ada softDeletes, log tidak boleh dihapus.
 *
 * Spec: Docs/ARUMED_INSURANCE_TPA_MODULE.md (Migration 6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insurance_claim_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('insurance_claim_id')
                ->constrained('insurance_claims')
                ->cascadeOnDelete();

            // Konvensi: audit user → uuid nullable tanpa FK
            $table->uuid('performed_by')->nullable();

            // CREATED, SUBMITTED, APPROVED, REJECTED, APPEALED, RESUBMITTED, NOTE_ADDED
            $table->string('action', 30);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->text('notes')->nullable();
            $table->jsonb('metadata')->nullable(); // submission_ref / rejection_code / dll

            $table->timestamp('performed_at');
            $table->timestamps();
            // Tidak ada softDeletes — log immutable

            $table->index('insurance_claim_id');
            $table->index('action');
            $table->index('performed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insurance_claim_logs');
    }
};
