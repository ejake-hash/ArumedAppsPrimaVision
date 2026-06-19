<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pipeline berkas klaim: tandai kunjungan BPJS yang sudah "Kirim ke Klaim" dari
 * Rekap (klaim_sent_at). Tab "DIVA & Berkas" di KlaimView hanya menampilkan yang
 * berpenanda ini. Saat dikembalikan dari Klaim ke Rekap, klaim_sent_at dikosongkan
 * lalu klaim_returned_at + klaim_return_note (pesan) diisi untuk ditampilkan di Rekap.
 * Forward-only menambah kolom, tidak menyentuh data lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->timestamp('klaim_sent_at')->nullable()->after('rekap_keterangan');
            $table->uuid('klaim_sent_by')->nullable()->after('klaim_sent_at');
            $table->timestamp('klaim_returned_at')->nullable()->after('klaim_sent_by');
            $table->text('klaim_return_note')->nullable()->after('klaim_returned_at');
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropColumn(['klaim_sent_at', 'klaim_sent_by', 'klaim_returned_at', 'klaim_return_note']);
        });
    }
};
