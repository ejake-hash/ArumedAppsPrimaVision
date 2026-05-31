<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Status oftalmologi ringkas pada CPPT rawat inap (RS Mata).
 *
 * Pada bangsal mata, observasi PPA lazimnya mencatat status mata singkat
 * di bawah tanda vital: VISUS (tajam penglihatan OD/OS) dan TIO/tonometri
 * (tekanan intraokular OD/OS + metode). Nama kolom mengikuti konvensi
 * refraction_records (visus string "6/6", iop decimal, iop_method NCT/Goldmann).
 *
 * Semua nullable → entri lama tetap valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nurse_cppt_entries', function (Blueprint $table) {
            $table->string('visus_od', 20)->nullable()->after('pain_scale');   // mis. 6/6, 6/60, 1/300, LP+
            $table->string('visus_os', 20)->nullable()->after('visus_od');
            $table->decimal('iop_od', 5, 2)->nullable()->after('visus_os');     // mmHg
            $table->decimal('iop_os', 5, 2)->nullable()->after('iop_od');
            $table->string('iop_method', 50)->nullable()->after('iop_os');      // NCT / Goldmann / Schiotz / Palpasi
        });
    }

    public function down(): void
    {
        Schema::table('nurse_cppt_entries', function (Blueprint $table) {
            $table->dropColumn(['visus_od', 'visus_os', 'iop_od', 'iop_os', 'iop_method']);
        });
    }
};
