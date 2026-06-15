<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RM 3.7 Pengkajian Gawat Darurat — Fase 1.
 * Perluas catatan triase IGD untuk Triase ATS (Australasian Triage Scale) +
 * skala nyeri + kondisi awal cepat. triage_level (sudah ada) kini diisi kategori
 * ATS '1'..'5'. Kolom baru menampung data triase cepat yang sebelumnya hilang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('igd_triage_records', function (Blueprint $table) {
            // Cara datang pasien ke RS (KELUARGA|SENDIRI|POLISI|LAINNYA).
            $table->string('arrival_mode', 20)->nullable()->after('chief_complaint');

            // Kondisi awal cepat (Pengkajian GD bagian Objektif ringkas).
            $table->string('keadaan_umum', 20)->nullable()->after('gcs_m');   // BAIK|SEDANG|LEMAH|BURUK
            $table->string('kesadaran', 20)->nullable()->after('keadaan_umum'); // CM|SOMNOLEN|KOMA
            $table->string('akral', 50)->nullable()->after('kesadaran');
            $table->string('reflex_cahaya', 50)->nullable()->after('akral');

            // Skala nyeri (NRS / WONG_BAKER untuk ≥6th & dewasa; FLACC untuk <6th).
            $table->smallInteger('pain_score')->nullable()->after('reflex_cahaya');   // 0..10
            $table->string('pain_scale_type', 15)->nullable()->after('pain_score');   // NRS|WONG_BAKER|FLACC
            $table->string('pain_location', 150)->nullable()->after('pain_scale_type');
            $table->jsonb('pain_detail')->nullable()->after('pain_location');         // sub-skor FLACC
        });
    }

    public function down(): void
    {
        Schema::table('igd_triage_records', function (Blueprint $table) {
            $table->dropColumn([
                'arrival_mode', 'keadaan_umum', 'kesadaran', 'akral', 'reflex_cahaya',
                'pain_score', 'pain_scale_type', 'pain_location', 'pain_detail',
            ]);
        });
    }
};
