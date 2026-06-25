<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Special CMG (top-up INA-CBG) — grouper 2-tahap.
 * Stage 1 grouper mengembalikan daftar opsi special CMG yang berlaku untuk kasus;
 * Stage 2 menerapkan pilihan → tarif berubah (DRG + top-up). Tanpa ini, prosedur
 * mata ber-top-up (mis. fakoemulsifikasi YY14-16) dibayar kurang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bpjs_claims', function (Blueprint $table) {
            // Daftar opsi dari grouper Stage 1: [{code,type,description,tariff}].
            $table->json('special_cmg_options')->nullable()->after('inacbgs_tarif');
            // Kode special CMG yang DIPILIH & dikirim di Stage 2 (mis. 'YY14').
            $table->string('special_cmg', 16)->nullable()->after('special_cmg_options');
            // Nilai top-up special CMG (selisih total - dasar) untuk transparansi.
            $table->decimal('tarif_top_up', 14, 2)->nullable()->after('special_cmg');
            // Total Cost Weight final (DRG CW + special CMG); pembanding DRG CW.
            $table->decimal('total_cost_weight', 8, 4)->nullable()->after('tarif_top_up');
        });
    }

    public function down(): void
    {
        Schema::table('bpjs_claims', function (Blueprint $table) {
            $table->dropColumn(['special_cmg_options', 'special_cmg', 'tarif_top_up', 'total_cost_weight']);
        });
    }
};
