<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pos kwitansi obat: pemisah baris OBAT di kwitansi menjadi pos berbeda
 * (Obat Pulang / Obat Tindakan / Obat Injeksi). Klasifikasi melekat di TARIF
 * obat (medication_tariffs), 1 obat = 1 pos tetap (kasir baca dari baris UMUM).
 *
 * Default 'OBAT_PULANG' → seluruh baris tarif lama langsung jatuh ke pos
 * "Obat Pulang" tanpa backfill manual. Lihat MedicationTariff::POS_LABELS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medication_tariffs', function (Blueprint $table) {
            $table->string('pos_kwitansi', 20)->default('OBAT_PULANG')->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('medication_tariffs', function (Blueprint $table) {
            $table->dropColumn('pos_kwitansi');
        });
    }
};
