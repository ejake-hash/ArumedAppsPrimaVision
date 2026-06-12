<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ganti unique penuh billing_invoices.visit_id → partial unique (hanya invoice
 * HIDUP, status != CANCELLED).
 *
 * Sebelumnya 1 baris invoice per kunjungan = mutlak. Sejak fitur "Batalkan
 * Tagihan" (status → CANCELLED, baris tetap tinggal sbg riwayat), menyusun ulang
 * tagihan meng-INSERT invoice baru sementara baris CANCELLED masih memegang
 * visit_id → SQLSTATE 23505 "billing_invoices_visit_id_unique".
 *
 * Partial index: boleh banyak invoice CANCELLED (riwayat batal) + paling banyak
 * SATU invoice aktif per kunjungan. Aman dibuat: data lama (unique penuh) pasti
 * tak punya duplikat visit_id aktif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table) {
            $table->dropUnique('billing_invoices_visit_id_unique');
        });

        DB::statement(
            "CREATE UNIQUE INDEX billing_invoices_visit_id_active_unique
             ON billing_invoices (visit_id)
             WHERE status <> 'CANCELLED'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS billing_invoices_visit_id_active_unique');

        Schema::table('billing_invoices', function (Blueprint $table) {
            $table->unique('visit_id', 'billing_invoices_visit_id_unique');
        });
    }
};
