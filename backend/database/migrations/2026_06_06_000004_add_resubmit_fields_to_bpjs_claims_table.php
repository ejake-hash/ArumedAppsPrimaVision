<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah field untuk alur "Ajukan Ulang" klaim BPJS:
 *  - resubmission_count : berapa kali klaim diajukan ulang (pola AsuransiService).
 *  - rejection_reason   : alasan penolakan terakhir (internal/BPJS).
 *  - rejected_at        : kapan ditolak.
 *
 * Status klaim (kolom `status` string) diperluas (tanpa ubah tipe):
 *  - DIKEMBALIKAN  : ditolak verifikator INTERNAL (sebelum submit) → bisa resubmit.
 *  - DITOLAK_BPJS  : dikembalikan/ditolak BPJS (setelah submit) → resubmit + count++.
 *  - DITOLAK (lama): tetap didukung, diperlakukan seperti DIKEMBALIKAN.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bpjs_claims', function (Blueprint $table) {
            $table->unsignedSmallInteger('resubmission_count')->default(0)->after('bpjs_response');
            $table->string('rejection_reason', 500)->nullable()->after('resubmission_count');
            $table->timestamp('rejected_at')->nullable()->after('rejection_reason');
        });
    }

    public function down(): void
    {
        Schema::table('bpjs_claims', function (Blueprint $table) {
            $table->dropColumn(['resubmission_count', 'rejection_reason', 'rejected_at']);
        });
    }
};
