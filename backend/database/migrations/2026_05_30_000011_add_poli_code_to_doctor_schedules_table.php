<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctor_schedules', function (Blueprint $table) {
            // Kode pendek poliklinik untuk prefix antrian: GLA, EKS, KAT, RET, ...
            // Prefix antrian = {poli_code}{room} (mis. GLA1). Nama lengkap tetap
            // disimpan di kolom `poliklinik` untuk tampilan ke pasien/TV.
            $table->string('poli_code', 10)->nullable()->after('poliklinik');
        });
    }

    public function down(): void
    {
        Schema::table('doctor_schedules', function (Blueprint $table) {
            $table->dropColumn('poli_code');
        });
    }
};
