<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O (Objektif) refraksionis kini editable & tersimpan (sebelumnya selalu
 * di-derive dari data refraksi via RmeAggregatorService::refraksiObjektif).
 *
 * Tambah `soap_o`: bila terisi dipakai apa adanya di timeline CPPT, bila NULL
 * aggregator tetap fallback derive (backward-compat record lama). Additive
 * nullable → prod-safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refraction_records', function (Blueprint $table) {
            $table->text('soap_o')->nullable()->after('soap_s');
        });
    }

    public function down(): void
    {
        Schema::table('refraction_records', function (Blueprint $table) {
            $table->dropColumn('soap_o');
        });
    }
};
