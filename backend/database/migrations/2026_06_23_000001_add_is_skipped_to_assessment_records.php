<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda "stasiun dilewati" (tidak diperlukan) untuk Triase & Refraksionis.
 *
 * Gate paralel ke DOKTER butuh NurseAssessment + RefractionRecord dua-duanya
 * is_finalized=true. Untuk pasien yang TIDAK perlu triase/refraksi, stasiun
 * di-skip → record di-finalize dengan is_skipped=true (tanpa data klinis) agar
 * antrean tetap jalan, sekaligus membedakannya dari asesmen yang benar-benar
 * diukur (penting untuk rekam medis / laporan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nurse_assessments', function (Blueprint $table) {
            $table->boolean('is_skipped')->default(false)->after('is_finalized');
        });
        Schema::table('refraction_records', function (Blueprint $table) {
            $table->boolean('is_skipped')->default(false)->after('is_finalized');
        });
    }

    public function down(): void
    {
        Schema::table('nurse_assessments', fn (Blueprint $t) => $t->dropColumn('is_skipped'));
        Schema::table('refraction_records', fn (Blueprint $t) => $t->dropColumn('is_skipped'));
    }
};
