<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah kolom JSONB `eye_drawings` pada pemeriksaan mata dokter — sketsa/anotasi
 * funduskopi & segmen anterior (OD/OS) yang digambar dokter di tab Pemeriksaan.
 *
 * Shape: { od: {strokes:<signature_pad toData>, png_base64:"...", template:"fundus"|"anterior"|null},
 *          os: {...} }
 * `strokes` = vektor (re-editable saat reopen); `png_base64` = raster untuk display/cetak.
 * Nullable → prod-safe (kolom kosong utk record lama).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctor_examinations', function (Blueprint $table) {
            $table->jsonb('eye_drawings')->nullable()->after('sp_notes');
        });
    }

    public function down(): void
    {
        Schema::table('doctor_examinations', function (Blueprint $table) {
            $table->dropColumn('eye_drawings');
        });
    }
};
