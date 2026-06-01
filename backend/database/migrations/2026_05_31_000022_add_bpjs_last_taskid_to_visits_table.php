<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda taskid BPJS Antrean terakhir yang sudah BERHASIL dikirim untuk visit ini.
 * Dasar guard monoton-naik di QueueService: hanya kirim updatewaktu bila taskid
 * baru > bpjs_last_taskid. Mencegah duplikat & waktu mundur (mis. pasien mata
 * bolak-balik DOKTER↔PENUNJANG memanggil dokter dua kali → task 4 tak terkirim ulang).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->unsignedSmallInteger('bpjs_last_taskid')->nullable()->after('bpjs_antrean_number');
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropColumn('bpjs_last_taskid');
        });
    }
};
