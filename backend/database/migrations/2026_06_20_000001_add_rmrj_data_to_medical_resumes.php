<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resume Medis Rawat Jalan (formulir RM 1.7/RMRJ/22) — field terstruktur.
 *
 * Resume sebelumnya bergaya SOAP (resume_s/o/a/p). Formulir resmi memakai field
 * lain (Anamnese, Pemeriksaan Fisik, Alergi Obat, Hasil Penunjang, Diagnosa,
 * Tindakan, Terapi, Riwayat Rawat Inap/Operasi, Instruksi/Edukasi, Kontrol).
 * Disimpan sebagai 1 kolom JSONB `rmrj_data` (pola Form Registry: data form
 * terstruktur dalam satu kolom) agar fleksibel untuk preview, edit, & cetak.
 * Kolom resume_s/o/a/p lama DIPERTAHANKAN demi backward-compat (RmeAggregator
 * & printResume lama masih membacanya).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_resumes', function (Blueprint $table) {
            $table->jsonb('rmrj_data')->nullable()->after('penunjang_results');
        });
    }

    public function down(): void
    {
        Schema::table('medical_resumes', function (Blueprint $table) {
            $table->dropColumn('rmrj_data');
        });
    }
};
