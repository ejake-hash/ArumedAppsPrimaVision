<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Kode faskes Kemenkes (urn:oid:kemkes) untuk Organization di FHIR Bundle
    // WS Rekam Medis BPJS. Kode PPK BPJS (urn:oid:bpjs) sudah ada via
    // IntegrationConfig.kode_faskes. Keduanya dibutuhkan Organization.identifier.
    public function up(): void
    {
        Schema::table('clinic_profiles', function (Blueprint $table) {
            $table->string('kemenkes_code', 30)->nullable()->after('clinic_code');
        });
    }

    public function down(): void
    {
        Schema::table('clinic_profiles', function (Blueprint $table) {
            $table->dropColumn('kemenkes_code');
        });
    }
};
