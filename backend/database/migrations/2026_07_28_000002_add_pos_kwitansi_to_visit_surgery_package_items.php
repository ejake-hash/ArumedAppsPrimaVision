<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pos kwitansi PER-KOMPONEN obat di snapshot paket pasien (Obat Tindakan/Injeksi/
 * Pulang). Operator memilihnya di tab Intraoperatif (Komponen Paket Pasien); dipakai
 * KasirService::buildPaketObatLines sebagai pos kwitansi obat komposisi (tetap terserap
 * paket). NULL = ikut default master tarif obat (perilaku lama, zero-diff).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visit_surgery_package_items', function (Blueprint $table) {
            $table->string('pos_kwitansi', 32)->nullable()->after('item_id');
        });
    }

    public function down(): void
    {
        Schema::table('visit_surgery_package_items', function (Blueprint $table) {
            $table->dropColumn('pos_kwitansi');
        });
    }
};
