<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lifecycle penerbitan SEP (rancangan "SEP keluar dari DB::transaction", 30 Jun 2026).
 *
 * Kolom observable agar penerbitan SEP bisa diserialisasi & kegagalan-parsial
 * (BPJS sukses tapi persist lokal gagal) TERLIHAT lewat query SQL biasa — penting
 * karena server tak punya scheduler/worker.
 *
 *   sep_status: NULL = belum pernah; ISSUING = sedang diterbitkan (klaim);
 *               ISSUED = sukses (no_sep terisi); FAILED = ditolak BPJS deterministik.
 *   sep_issuing_at: penanda waktu klaim untuk deteksi stuck-ISSUING (TTL).
 *
 * Additive & zero-downtime. no_sep (UNIQUE) tetap sumber kebenaran utama & guard
 * anti-dobel. Kompatibel mundur: kode lama mengabaikan kolom ini → aman di-deploy
 * SEBELUM kode state-machine diaktifkan via feature flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->string('sep_status', 12)->nullable()->after('sep_data');
            $table->timestamp('sep_issuing_at')->nullable()->after('sep_status');
            $table->index('sep_status');
        });

        // Backfill: SEP lama (no_sep terisi) konsisten observable sebagai ISSUED.
        DB::table('visits')->whereNotNull('no_sep')->update(['sep_status' => 'ISSUED']);
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropIndex(['sep_status']);
            $table->dropColumn(['sep_status', 'sep_issuing_at']);
        });
    }
};
