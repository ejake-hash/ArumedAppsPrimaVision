<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Diagnosa awal kunjungan (untuk SEP BPJS).
 *
 * SEP VClaim mewajibkan field `diagAwal` (kode ICD-10) terisi — bila kosong BPJS
 * menolak dengan "Diagnosa Awal Tidak Boleh Kosong". Diagnosa ini sebenarnya sudah
 * ada di data rujukan FKTP (rujukan.diagnosa.kode/nama), tapi selama ini tak pernah
 * disimpan maupun dikirim saat penerbitan SEP. Kolom ini menyimpan kode + nama
 * diagnosa (hasil "Tarik dari BPJS" / auto-resolve dari rujukan saat terbit SEP /
 * input manual petugas) supaya bisa ditampilkan di Detail Kunjungan & dipakai SEP.
 * NULL = belum ada (perilaku lama, zero-diff).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->string('diagnosa_awal', 16)->nullable()->after('no_surat_kontrol');
            $table->string('diagnosa_awal_nama')->nullable()->after('diagnosa_awal');
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropColumn(['diagnosa_awal', 'diagnosa_awal_nama']);
        });
    }
};
