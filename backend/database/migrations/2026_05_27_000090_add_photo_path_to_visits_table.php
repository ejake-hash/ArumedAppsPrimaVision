<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foto pasien bersifat per-kunjungan: tiap kunjungan menyimpan fotonya sendiri
 * (diambil saat registrasi), sehingga riwayat menampilkan banyak foto sesuai
 * jumlah tanggal kunjungan. patients.photo_path tetap dipakai sebagai foto
 * "terbaru" untuk avatar di pencarian/stasiun.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->string('photo_path')->nullable()->after('no_sep');
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropColumn('photo_path');
        });
    }
};
