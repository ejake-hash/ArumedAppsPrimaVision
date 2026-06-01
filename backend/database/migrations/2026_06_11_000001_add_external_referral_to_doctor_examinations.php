<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rujukan EKSTERNAL non-BPJS (faskes lain) yang ditulis dokter di Tab 4 saat
 * planning RUJUK. Pasien BPJS rujuk lewat VClaim (bpjs_referral_in_id / tabel
 * terpisah); pasien internal lewat internal_referral_*. Untuk non-BPJS yang
 * dirujuk ke RS/klinik lain belum ada tempat penyimpanan -> data hilang saat
 * finalisasi. Dua kolom ini menutup celah itu (tercatat di RME & cetak resume).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctor_examinations', function (Blueprint $table) {
            $table->string('external_referral_facility')->nullable()->after('surgery_schedule_id');
            $table->string('external_referral_reason', 500)->nullable()->after('external_referral_facility');
        });
    }

    public function down(): void
    {
        Schema::table('doctor_examinations', function (Blueprint $table) {
            $table->dropColumn(['external_referral_facility', 'external_referral_reason']);
        });
    }
};
