<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nomor registrasi/pendaftaran resmi per kunjungan, terpisah dari nomor antrean.
 * Format: REG-YYYYMMDD-NNN (sequence harian). Di-generate saat pasien resmi
 * terdaftar (registerVisit / daftarkanWalkIn). Nullable supaya placeholder
 * kiosk yang belum didaftarkan tidak punya nomor (NULL ≠ NULL pada unique).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->string('no_registrasi', 30)->nullable()->unique()->after('no_antreen');
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropColumn('no_registrasi');
        });
    }
};
