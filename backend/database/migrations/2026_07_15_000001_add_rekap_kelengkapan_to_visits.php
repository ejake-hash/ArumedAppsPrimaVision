<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Screening pra-klaim (Rekap Kunjungan BPJS): petugas menandai kelengkapan
 * berkas klaim per kunjungan + keterangan bebas (KET). Murni diisi manual —
 * `berkas_lengkap` NULL = belum dicek, true = Lengkap, false = Belum Lengkap.
 * Forward-only menambah kolom, tidak menyentuh data lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->boolean('berkas_lengkap')->nullable()->after('discharge_summary');
            $table->uuid('berkas_lengkap_by')->nullable()->after('berkas_lengkap');
            $table->timestamp('berkas_lengkap_at')->nullable()->after('berkas_lengkap_by');
            $table->string('rekap_keterangan', 500)->nullable()->after('berkas_lengkap_at');
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropColumn(['berkas_lengkap', 'berkas_lengkap_by', 'berkas_lengkap_at', 'rekap_keterangan']);
        });
    }
};
