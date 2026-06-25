<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S3 — kode LOINC per jenis penunjang (OCT/USG/biometri/dll) untuk
 * ServiceRequest/DiagnosticReport SATUSEHAT (radiologi wajib LOINC).
 * Nilai LOINC diisi terpisah (jangan hardcode dari ingatan — verifikasi ke
 * valueset resmi). Builder fallback ke CodeableConcept lokal bila kosong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('diagnostic_test_types', function (Blueprint $table) {
            $table->string('loinc_code', 20)->nullable()->after('modality');
        });
    }

    public function down(): void
    {
        Schema::table('diagnostic_test_types', function (Blueprint $table) {
            $table->dropColumn('loinc_code');
        });
    }
};
