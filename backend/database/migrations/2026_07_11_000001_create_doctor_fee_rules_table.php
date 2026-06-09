<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aturan honor (jasa medis) dokter untuk modul Keuangan.
 *
 * Satu tabel, tiga bentuk aturan dibedakan `rule_type`:
 *  - PERCENT_CATEGORY : honor = persen × jumlah tarif per kategori (PKS),
 *                       boleh beda per dokter (employee_id) & per penjamin.
 *  - PERCENT_PAYER    : varian PERCENT yang spesifik penjamin (BPJS vs UMUM).
 *  - NOMINAL_PACKAGE  : honor nominal tetap per kasus paket bedah (edaran).
 *
 * Resolusi "paling spesifik menang" dihitung di KeuanganService (skor kolom).
 * Versi PKS/edaran dipertahankan via effective_from/effective_to + softDeletes —
 * jangan hard-delete agar rekap periode lampau tetap reprodusibel.
 *
 * Tidak mengubah tabel billing — atribusi dokter & payer dihitung on-the-fly
 * dari snapshot billing_items historis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_fee_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // NULL = aturan global/default berlaku untuk semua dokter.
            $table->uuid('employee_id')->nullable();

            $table->enum('rule_type', ['PERCENT_CATEGORY', 'PERCENT_PAYER', 'NOMINAL_PACKAGE']);

            // Cocokkan label kategori beku di billing_items.category. NULL = semua kategori.
            $table->string('category', 150)->nullable();

            // Hanya untuk NOMINAL_PACKAGE.
            $table->uuid('surgery_package_id')->nullable();

            // BPJS | UMUM. NULL = berlaku untuk kedua kelompok.
            $table->enum('payer_group', ['BPJS', 'UMUM'])->nullable();

            $table->decimal('percent', 5, 2)->nullable();   // 80.00 = 80%
            $table->decimal('nominal', 15, 2)->nullable();  // honor tetap per kasus

            // Persen dikalikan ke total_price (GROSS) atau net_price (NET, setelah diskon).
            $table->enum('basis', ['GROSS', 'NET'])->default('NET');

            $table->date('effective_from');
            $table->date('effective_to')->nullable();        // NULL = masih berlaku

            $table->string('label', 200)->nullable();        // mis. "PKS dr.X 2026 / Edaran 014"
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('employee_id')->references('id')->on('employees')->nullOnDelete();
            $table->foreign('surgery_package_id')->references('id')->on('surgery_packages')->nullOnDelete();

            $table->index(['employee_id', 'payer_group', 'category', 'effective_from'], 'dfr_resolve_idx');
            $table->index('surgery_package_id');
            $table->index('rule_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_fee_rules');
    }
};
