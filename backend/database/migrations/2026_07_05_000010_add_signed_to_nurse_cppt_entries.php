<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paraf penulis CPPT (tanda tangan PIN per-PPA) untuk nurse_cppt_entries.
 *
 * BEDA dari verifikasi DPJP (`verified_by_id`/`verified_at` yang sudah ada):
 *  - signed_*    = paraf PENULIS entri (Perawat/Refraksionis/dll) via PIN → badge "Ditandatangani".
 *  - verified_*  = review/verifikasi DPJP atas entri tsb → badge "Terverifikasi DPJP".
 * Keduanya hidup berdampingan. Additive nullable, prod-safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nurse_cppt_entries', function (Blueprint $table) {
            $table->timestamp('signed_at')->nullable()->after('edited_by_id');
            $table->uuid('signed_by_id')->nullable()->after('signed_at');
            $table->foreign('signed_by_id')->references('id')->on('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('nurse_cppt_entries', function (Blueprint $table) {
            $table->dropForeign(['signed_by_id']);
            $table->dropColumn(['signed_at', 'signed_by_id']);
        });
    }
};
