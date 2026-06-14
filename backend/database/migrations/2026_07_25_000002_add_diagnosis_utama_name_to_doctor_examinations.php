<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Simpan NAMA sub-diagnosa spesifik yang dipilih dokter untuk diagnosa utama, agar
 * Resume/RME menampilkan diagnosa yang tepat (bukan nama generik kode). Kode kanonik
 * tetap di `diagnosis_utama`. Sekunder & tindakan membawa nama di dalam jsonb
 * ({code,name}) — tak butuh kolom baru. Null = data lama → fallback nama dari master.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctor_examinations', function (Blueprint $table) {
            $table->string('diagnosis_utama_name', 500)->nullable()->after('diagnosis_utama');
        });
    }

    public function down(): void
    {
        Schema::table('doctor_examinations', function (Blueprint $table) {
            $table->dropColumn('diagnosis_utama_name');
        });
    }
};
