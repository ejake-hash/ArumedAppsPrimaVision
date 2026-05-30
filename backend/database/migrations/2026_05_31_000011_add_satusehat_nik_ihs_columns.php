<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 Bridging Satu Sehat — gap data untuk resolve IHS (Encounter/Condition).
 *
 *  - employees.nik            : NIK dokter -> di-resolve jadi Practitioner IHS.
 *  - employees.satusehat_ihs  : cache IHS dokter (hindari resolve berulang).
 *  - patients.satusehat_ihs   : cache IHS pasien (NIK pasien sudah ada).
 *
 * kfa_code (medications) SENGAJA DITUNDA ke Fase 4 (MedicationRequest/Dispense).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('nik', 32)->nullable()->after('sip')->index();
            $table->string('satusehat_ihs', 64)->nullable()->after('bpjs_dpjp_code');
        });

        Schema::table('patients', function (Blueprint $table) {
            $table->string('satusehat_ihs', 64)->nullable()->after('nik');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['nik', 'satusehat_ihs']);
        });

        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn('satusehat_ihs');
        });
    }
};
