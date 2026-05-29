<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Asuransi/TPA Non-BPJS — workflow klaim TPA (bukan BPJS).
 *
 * Status: DRAFT → SUBMITTED → APPROVED | REJECTED → (revisi) → SUBMITTED
 *         SUBMITTED → APPEALED → APPROVED | REJECTED
 *
 * Spec: Docs/ARUMED_INSURANCE_TPA_MODULE.md (Migration 5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insurance_claims', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('visit_id')
                ->constrained('visits')
                ->cascadeOnDelete();
            $table->foreignUuid('insurer_id')
                ->constrained('insurers')
                ->restrictOnDelete();
            $table->foreignUuid('billing_invoice_id')
                ->nullable()
                ->constrained('billing_invoices')
                ->nullOnDelete();
            $table->foreignUuid('insurance_verification_id')
                ->nullable()
                ->constrained('insurance_verifications')
                ->nullOnDelete();

            // Konvensi: audit user → uuid nullable tanpa FK
            $table->uuid('submitted_by')->nullable();

            $table->enum('status', [
                'DRAFT',
                'SUBMITTED',
                'APPROVED',
                'REJECTED',
                'APPEALED',
            ])->default('DRAFT');

            // Nominal
            $table->decimal('claim_amount', 15, 2)->default(0);          // Total klaim ke TPA
            $table->decimal('approved_amount', 15, 2)->nullable();        // Disetujui TPA
            $table->decimal('patient_responsibility', 15, 2)->default(0); // Copay/selisih pasien

            // Submission tracking
            $table->string('submission_ref')->nullable();                 // Nomor referensi portal TPA
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();

            // Dokumen checklist — JSONB { "Resume Medis": true, "Kwitansi Asli": false }
            $table->jsonb('documents_checklist')->nullable();

            // Reject & appeal
            $table->string('rejection_code')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->integer('resubmission_count')->default(0);
            $table->text('appeal_notes')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('visit_id');
            $table->index('insurer_id');
            $table->index('billing_invoice_id');
            $table->index('status');
            $table->index('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insurance_claims');
    }
};
