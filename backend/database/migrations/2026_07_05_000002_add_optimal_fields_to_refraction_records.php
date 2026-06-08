<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penyesuaian field refraksionis ke skema pemeriksaan optimal:
 *
 *  1. Keratometri axis TERPISAH K1 & K2 — kolom `keratometri_axis_*` yang ada
 *     diperlakukan sebagai axis K1; tambah `keratometri_axis2_*` = axis K2
 *     (format K-meter standar: K1@axis1 / K2@axis2).
 *  2. Tonometri berulang — pengukuran #1 tetap di `iop_od/iop_os` (+ `iop_method`
 *     bersama); pengukuran ulang ditambah manual dari UI, disimpan sbg array JSON
 *     [{ "od": n, "os": n }, ...] tanpa batas jumlah & metode tetap tunggal.
 *  3. Visus dengan kacamata lama (presenting VA dgn koreksi lama) — kolom baru.
 *
 * Semua additive nullable → prod-safe, rollback aman. Tidak menyentuh SOAP/PIN.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refraction_records', function (Blueprint $table) {
            // 1. Keratometri axis K2 (axis K1 = kolom keratometri_axis_* yang sudah ada)
            $table->integer('keratometri_axis2_od')->nullable()->after('keratometri_axis_od');
            $table->integer('keratometri_axis2_os')->nullable()->after('keratometri_axis_os');

            // 2. Pengukuran IOP berulang (manual, dinamis) — metode = iop_method bersama
            $table->json('iop_extra_readings')->nullable()->after('iop_method');

            // 3. Visus dengan kacamata lama (presenting VA)
            $table->string('old_glasses_visus_od', 20)->nullable()->after('old_glasses_add_od');
            $table->string('old_glasses_visus_os', 20)->nullable()->after('old_glasses_add_os');
        });
    }

    public function down(): void
    {
        Schema::table('refraction_records', function (Blueprint $table) {
            $table->dropColumn([
                'keratometri_axis2_od',
                'keratometri_axis2_os',
                'iop_extra_readings',
                'old_glasses_visus_od',
                'old_glasses_visus_os',
            ]);
        });
    }
};
