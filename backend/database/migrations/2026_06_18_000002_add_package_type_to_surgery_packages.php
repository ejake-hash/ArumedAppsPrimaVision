<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paket bukan hanya bedah — poliklinik juga punya paket pemeriksaan.
 * Kolom `package_type` membedakan BEDAH (komponen PROCEDURE/BHP/IOL) vs
 * PEMERIKSAAN (komponen PROCEDURE saja, dipilih dokter di Tab Tindakan).
 * Mekanisme snapshot + diskon kasir identik untuk keduanya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surgery_packages', function (Blueprint $table) {
            $table->string('package_type', 20)->default('BEDAH')->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('surgery_packages', function (Blueprint $table) {
            $table->dropColumn('package_type');
        });
    }
};
