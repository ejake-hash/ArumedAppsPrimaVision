<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pos kwitansi PER-BARIS resep (override klasifikasi master tarif obat).
 *
 * Default pos obat di kwitansi diturunkan dari medication_tariffs.pos_kwitansi
 * (satu nilai per obat). Tapi peran obat bisa beda per konteks: obat yang sama
 * bisa "Obat Tindakan" saat dipakai intra-op dan "Obat Pulang" saat dibawa pulang.
 * Operator Bedah (Obat Pasca Bedah) memilih pos yang benar saat input → tersimpan
 * di sini. NULL = ikut default master (perilaku lama, zero-diff).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prescription_items', function (Blueprint $table) {
            $table->string('pos_kwitansi', 32)->nullable()->after('route');
        });
    }

    public function down(): void
    {
        Schema::table('prescription_items', function (Blueprint $table) {
            $table->dropColumn('pos_kwitansi');
        });
    }
};
