<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Setting elemen yang tampil di cetak kwitansi/rincian kasir (logo, stempel,
 * e-sign kasir, watermark, footer direktur). Disimpan per klinik sebagai JSON
 * supaya admin bisa atur dari UI tanpa migrasi tiap kali ada toggle baru.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinic_profiles', function (Blueprint $table) {
            $table->json('receipt_print_settings')->nullable()->after('watermark_type');
        });
    }

    public function down(): void
    {
        Schema::table('clinic_profiles', function (Blueprint $table) {
            $table->dropColumn('receipt_print_settings');
        });
    }
};
