<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * prescriptions.type — diskriminator alur resep.
 *   RAJAL = resep rawat jalan + obat pulang rawat inap (di-dispense di loket
 *           Farmasi lewat antrean; perilaku lama, default semua data lama).
 *   RANAP = PERMINTAAN OBAT pasien rawat inap SELAMA dirawat — TIDAK lewat
 *           antrean Farmasi, di-dispense ke ruangan via tab "Dispensing Rawat
 *           Inap" (potong stok + tagih inpatient_charges saat serah).
 *
 * Aditif & prod-safe: kolom default 'RAJAL' → tak mengubah perilaku resep lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prescriptions', function (Blueprint $table) {
            $table->string('type', 20)->default('RAJAL')->after('status')->index();
        });
    }

    public function down(): void
    {
        Schema::table('prescriptions', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
