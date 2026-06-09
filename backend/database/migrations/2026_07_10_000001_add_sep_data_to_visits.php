<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Simpan SNAPSHOT data SEP saat diterbitkan (response BPJS + field yang kita
     * kirim). VClaim 2.0 TIDAK punya endpoint "GET SEP by noSep" generik untuk
     * re-fetch detail, jadi cetak SEP harus dari data lokal. Kolom JSON ini jadi
     * sumber tunggal cetak (offline-capable, tak perlu panggil BPJS lagi).
     */
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->json('sep_data')->nullable()->after('no_sep');
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropColumn('sep_data');
        });
    }
};
