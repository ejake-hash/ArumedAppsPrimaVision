<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah field kop surat (letterhead) ke clinic_profiles agar header dokumen
 * bisa disusun dari data (data-driven), menyerupai kop resmi:
 *
 *   <subtitle>          mis. "RUMAH SAKIT KHUSUS MATA"
 *   <clinic_name>       mis. "PRIMA VISION"
 *   <tagline>           mis. "VISION FOR THE NATION"
 *   <unit_line>         mis. "PRIMA VISION EYE HOSPITAL - 24 HOURS ... EMERGENCY UNIT"
 *   <address>
 *   HOSPITAL HOTLINE          : <phone>
 *   24 HOURS EMERGENCY HOTLINE : <emergency_hotline>
 *   EMAIL                     : <email>
 *
 * Semua nullable — kop lama (logo + nama + alamat) tetap jalan tanpa diisi.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('clinic_profiles', function (Blueprint $table) {
            $table->string('subtitle', 255)->nullable()->after('clinic_name');
            $table->string('tagline', 255)->nullable()->after('subtitle');
            $table->string('unit_line', 500)->nullable()->after('tagline');
            $table->string('emergency_hotline', 50)->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('clinic_profiles', function (Blueprint $table) {
            $table->dropColumn(['subtitle', 'tagline', 'unit_line', 'emergency_hotline']);
        });
    }
};
