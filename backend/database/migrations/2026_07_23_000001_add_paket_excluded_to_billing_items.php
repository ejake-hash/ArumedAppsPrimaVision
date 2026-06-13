<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Model OPT-OUT penyerapan baris tagihan ke paket bedah.
 *
 * billing_items.paket_excluded (OTORITATIF, dikontrol Kasir):
 *  - false (default) = baris TERSERAP ke harga paket (pasien bayar = sell_price paket;
 *    nilainya masuk basis DISKON_PAKET).
 *  - true = baris DIKELUARKAN kasir → ditagih ekstra di atas harga paket.
 *
 * Menggantikan keputusan serap yang sebelumnya opt-in & tersebar di tabel sumber
 * (prescription_items.is_paket_absorbed / surgery_request_bhp.is_paket_absorbed).
 * Kolom lama dibiarkan (dibersihkan terpisah setelah masa pantau). Default false =
 * semua baris extra otomatis terserap; baris yang dulu "tidak terserap" kini terserap
 * kecuali kasir mengeluarkannya (perubahan perilaku disengaja).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_items', function (Blueprint $table) {
            $table->boolean('paket_excluded')->default(false)->after('is_absorbed');
        });
    }

    public function down(): void
    {
        Schema::table('billing_items', function (Blueprint $table) {
            $table->dropColumn('paket_excluded');
        });
    }
};
