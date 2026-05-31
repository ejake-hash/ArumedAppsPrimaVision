<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom `raw_data` (jsonb) di refraction_records untuk migrasi Prima Vision → Arumed.
 *
 * Data autoref/refraksi Prima Vision sangat kotor (~22,6% string autoref tidak
 * mengikuti format standar: literal "error", desimal koma "0,50", notasi ×100
 * "S-275", silinder tanpa "S", dll). Parser menulis hasil ke kolom numerik
 * (autoref_*_sph/cyl/axis, dst); string ASLI yang gagal/ambigu disimpan apa
 * adanya di sini sebagai JSON, contoh:
 *
 *   { "autoref_od": "S-275 C-125 X 150", "autoref_os": "error",
 *     "visus_od": "px tidak mau visus", "_source": "pemeriksaan_ro" }
 *
 * Manfaat: bila parsing ternyata keliru, kolom numerik bisa di-RE-PARSE ulang
 * langsung dari raw_data di DB Arumed — tanpa menyentuh sumber Prima Vision lagi.
 * jsonb (bukan json) agar bisa di-query/index bila perlu. Hanya terisi untuk
 * baris hasil migrasi; data baru dari UI biarkan NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refraction_records', function (Blueprint $table) {
            $table->jsonb('raw_data')->nullable()->after('clinical_notes');
        });
    }

    public function down(): void
    {
        Schema::table('refraction_records', function (Blueprint $table) {
            $table->dropColumn('raw_data');
        });
    }
};
