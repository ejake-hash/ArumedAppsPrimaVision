<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda "terserap ke paket bedah" yang DIPUTUSKAN KASIR per baris tagihan
 * (generalisasi is_preop_absorbed yang di-set Perawat):
 *
 * - prescription_items.is_paket_absorbed: obat tambahan (pasca-bedah / tambahan
 *   Farmasi) tetap TAMPIL positif di kwitansi; nilainya ikut basis DISKON_PAKET
 *   sehingga total bersih tetap = harga jual paket.
 * - surgery_request_bhp.is_paket_absorbed: BHP terpakai (used_qty>0) di luar
 *   komposisi paket — perlakuan sama.
 *
 * Terpisah dari is_preop_absorbed (aktor & alur beda: Perawat saat instruksi
 * pre-op vs Kasir saat rincian); keduanya menambah basis diskon yang sama.
 * Forward-only menambah kolom — tidak mengubah/menghapus data lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prescription_items', function (Blueprint $table) {
            $table->boolean('is_paket_absorbed')->default(false)->after('is_preop_absorbed');
        });

        Schema::table('surgery_request_bhp', function (Blueprint $table) {
            $table->boolean('is_paket_absorbed')->default(false)->after('used_qty');
        });
    }

    public function down(): void
    {
        Schema::table('prescription_items', function (Blueprint $table) {
            $table->dropColumn('is_paket_absorbed');
        });

        Schema::table('surgery_request_bhp', function (Blueprint $table) {
            $table->dropColumn('is_paket_absorbed');
        });
    }
};
