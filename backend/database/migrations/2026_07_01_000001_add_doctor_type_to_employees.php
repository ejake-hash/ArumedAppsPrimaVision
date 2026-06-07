<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah `doctor_type` ke employees untuk membedakan jenis dokter:
 *   SPESIALIS_MATA = dokter poliklinik (PUNYA jadwal)
 *   UMUM           = dokter umum / IGD (TIDAK punya jadwal)
 *   ANESTESI       = dokter anestesi (TIDAK punya jadwal; dipilih di modul Bedah)
 * NULL untuk non-dokter.
 *
 * Backfill data dokter yang sudah ada dari `profession` (urut: anestesi → umum/igd →
 * spesialis) sekaligus merapikan label profession. Keyword bersifat dokter-spesifik
 * sehingga non-dokter (Perawat/Apoteker/dll) tidak tersentuh.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('doctor_type', 20)->nullable()->after('profession');
            $table->index('doctor_type');
        });

        DB::table('employees')->whereNull('doctor_type')->where('profession', 'ilike', '%anestesi%')
            ->update(['doctor_type' => 'ANESTESI', 'profession' => 'Dokter Anestesi']);

        DB::table('employees')->whereNull('doctor_type')
            ->where(fn ($q) => $q->where('profession', 'ilike', '%igd%')->orWhere('profession', 'ilike', '%umum%'))
            ->update(['doctor_type' => 'UMUM', 'profession' => 'Dokter Umum']);

        DB::table('employees')->whereNull('doctor_type')->where('profession', 'ilike', '%spesialis%')
            ->update(['doctor_type' => 'SPESIALIS_MATA', 'profession' => 'Dokter Spesialis Mata']);
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['doctor_type']);
            $table->dropColumn('doctor_type');
        });
    }
};
