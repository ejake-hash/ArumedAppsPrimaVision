<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda resep obat PRE-OPERASI (instruksi dokter jaga di Triase utk pasien
 * PREOP_BEDAH, stat-dose mis. obat tensi/gula sebelum naik OT) — BUKAN resep
 * dokter Tab 3 dan BUKAN resep pasca-bedah.
 *
 * - prescriptions.is_pre_op: pembeda agar REPLACE resep dokter / pasca-bedah
 *   tidak menghapus instruksi dokter jaga (dan sebaliknya).
 * - prescription_items.is_preop_absorbed: item "terserap ke paket" — tetap
 *   TAMPIL positif di kwitansi & tetap lewat Farmasi; nilainya ikut basis
 *   DISKON_PAKET sehingga total bersih tetap = harga jual paket.
 *   (Sengaja BUKAN is_bedah: is_bedah menyembunyikan baris dari tagihan.)
 *
 * Forward-only menambah kolom — tidak mengubah/menghapus data lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prescriptions', function (Blueprint $table) {
            $table->boolean('is_pre_op')->default(false)->after('is_post_op');
        });

        Schema::table('prescription_items', function (Blueprint $table) {
            $table->boolean('is_preop_absorbed')->default(false)->after('is_bedah');
        });
    }

    public function down(): void
    {
        Schema::table('prescriptions', function (Blueprint $table) {
            $table->dropColumn('is_pre_op');
        });

        Schema::table('prescription_items', function (Blueprint $table) {
            $table->dropColumn('is_preop_absorbed');
        });
    }
};
