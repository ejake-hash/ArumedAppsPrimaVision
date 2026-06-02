<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * doctor_examinations.diagnosis_text — diagnosa NARATIF (teks bebas) yang ditulis
 * dokter saat ragu/belum menemukan kode ICD-10 yang sesuai. Melengkapi
 * diagnosis_utama/sekunder (kode). Dibaca verifikator di KlaimView untuk membantu
 * pemetaan kode saat klaim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctor_examinations', function (Blueprint $table) {
            $table->text('diagnosis_text')->nullable()->after('diagnosis_sekunder');
        });
    }

    public function down(): void
    {
        Schema::table('doctor_examinations', function (Blueprint $table) {
            $table->dropColumn('diagnosis_text');
        });
    }
};
