<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COB (Coordination of Benefits) — porsi tanggungan PER penjamin atas satu invoice.
 *
 * Sebelumnya billing_invoices hanya punya 1 `covered_amount` (asumsi 1 penjamin).
 * Untuk COB (mis. penjamin-1 BPJS bayar INA-CBG, penjamin-2 asuransi bayar selisih),
 * tiap penjamin punya 1 baris di sini. `billing_invoices.covered_amount` dipertahankan
 * sebagai AGREGAT (Σ coverages) demi backward-compat kwitansi/laporan/kasir.
 *
 * Identitas: (billing_invoice_id, sequence) — sequence 1 = penjamin-1, 2 = penjamin-2.
 * Pakai SoftDeletes + pola upsert soft-delete aware (restore bila konsolidasi ulang).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_invoice_coverages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('billing_invoice_id')->constrained('billing_invoices')->cascadeOnDelete();
            $table->foreignUuid('insurer_id')->constrained('insurers')->restrictOnDelete();
            $table->string('guarantor_type', 20);          // BPJS / ASURANSI / PERUSAHAAN
            $table->unsignedSmallInteger('sequence');        // 1 = penjamin-1, 2 = penjamin-2
            $table->decimal('covered_amount', 15, 2)->default(0);
            $table->decimal('basis_amount', 15, 2)->nullable(); // total@harga-penjamin-ini (audit; p2 = recompute)
            $table->foreignUuid('verification_id')->nullable()->constrained('insurance_verifications')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['billing_invoice_id', 'sequence'], 'binv_cov_invoice_seq_unique');
            $table->index('billing_invoice_id');
            $table->index('insurer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_invoice_coverages');
    }
};
