<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modalitas DICOM per jenis penunjang (untuk integrasi alat OCT/USG).
 *
 * Sebelumnya modalitas dipetakan HANYA dari kode tetap (OCT/USG/BIOM) di
 * config/penunjang_dicom.php → jenis baru (kode auto PNJ-xxx) tak terpetakan
 * → default OT → tak muncul di worklist alat. Kolom ini membuat modalitas
 * BISA DIATUR PER-JENIS dari UI master, jadi "OCT Fundus/Glaukoma/Papil" baru
 * cukup dipilih modalitasnya = OPT agar masuk worklist OCT.
 *
 * Nilai: 'OPT' (OCT), 'US' (USG/Biometri), 'OT' (lainnya), atau NULL (fallback
 * ke peta config lama). AccessionService::modalityFor() membaca kolom ini dulu.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('diagnostic_test_types', 'modality')) {
            Schema::table('diagnostic_test_types', function (Blueprint $table) {
                $table->string('modality', 8)->nullable()->after('category');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('diagnostic_test_types', 'modality')) {
            Schema::table('diagnostic_test_types', function (Blueprint $table) {
                $table->dropColumn('modality');
            });
        }
    }
};
