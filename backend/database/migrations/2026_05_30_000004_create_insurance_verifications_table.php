<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Asuransi/TPA Non-BPJS — hasil verifikasi eligibility per kunjungan.
 * Diinput billing setelah cek manual ke portal TPA.
 *
 * Spec: Docs/ARUMED_INSURANCE_TPA_MODULE.md (Migration 4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insurance_verifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')
                ->constrained('visits')
                ->cascadeOnDelete();
            $table->foreignUuid('insurer_id')
                ->constrained('insurers')
                ->restrictOnDelete();

            // Konvensi project: audit user → uuid nullable tanpa FK constraint
            $table->uuid('verified_by')->nullable();

            $table->enum('status', [
                'PENDING',
                'VERIFIED',
                'NEEDS_CLARIFICATION',
                'REJECTED',
            ])->default('PENDING');

            // Coverage info
            $table->string('policy_number')->nullable();
            $table->string('member_name')->nullable();
            $table->string('member_card_number')->nullable();
            $table->decimal('plafon_amount', 15, 2)->nullable();    // NULL = unlimited / unknown
            $table->decimal('copayment_percent', 5, 2)->default(0); // % ditanggung pasien
            $table->decimal('copayment_amount', 15, 2)->default(0); // Nominal copay fix

            $table->text('coverage_notes')->nullable();
            $table->jsonb('exclusion_flags')->nullable();            // ["KACAMATA", ...]
            $table->text('issue_notes')->nullable();                 // Penjelasan jika ISSUE / NEEDS_CLARIFICATION

            $table->timestamp('verified_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('visit_id');
            $table->index('insurer_id');
            $table->index('status');
            $table->index('verified_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insurance_verifications');
    }
};
