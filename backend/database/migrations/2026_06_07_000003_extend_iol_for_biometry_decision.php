<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fitur Biometri (Quantel) → Keputusan IOL dokter → Bedah.
 *
 *  - `iol_items.a_constant`: A-constant lensa (SRK/T) → dipakai memetakan baris
 *    tabel hitung Quantel (per A-constant) ke lensa master yang ada stoknya.
 *  - `iol_recommendations` diperluas dari sekadar "rekomendasi" menjadi
 *    "KEPUTUSAN IOL" dokter: lensa master terpilih (iol_item_id), formula,
 *    A-constant, target & prediksi refraksi, siapa/kapan memutuskan, flag final.
 *    Keputusan final inilah yang dibaca Bedah untuk request IOL/BHP ke gudang.
 *
 * Idempoten (hasColumn guard) — aman di-run ulang / migrate:refresh.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('iol_items', 'a_constant')) {
            Schema::table('iol_items', function (Blueprint $table) {
                $table->decimal('a_constant', 6, 3)->nullable()->after('power');
            });
        }

        Schema::table('iol_recommendations', function (Blueprint $table) {
            if (! Schema::hasColumn('iol_recommendations', 'iol_item_id')) {
                $table->foreignUuid('iol_item_id')->nullable()->after('diagnostic_result_id')
                    ->constrained('iol_items')->nullOnDelete();
            }
            if (! Schema::hasColumn('iol_recommendations', 'formula')) {
                $table->string('formula', 30)->nullable()->after('recommended_power');
            }
            if (! Schema::hasColumn('iol_recommendations', 'a_constant')) {
                $table->decimal('a_constant', 6, 3)->nullable()->after('formula');
            }
            if (! Schema::hasColumn('iol_recommendations', 'target_refraction')) {
                $table->decimal('target_refraction', 5, 2)->nullable()->after('a_constant');
            }
            if (! Schema::hasColumn('iol_recommendations', 'predicted_refraction')) {
                $table->decimal('predicted_refraction', 6, 3)->nullable()->after('target_refraction');
            }
            if (! Schema::hasColumn('iol_recommendations', 'is_final')) {
                $table->boolean('is_final')->default(false)->after('is_approved');
            }
            if (! Schema::hasColumn('iol_recommendations', 'decided_by_id')) {
                $table->foreignUuid('decided_by_id')->nullable()->after('approved_at')
                    ->constrained('employees')->nullOnDelete();
            }
            if (! Schema::hasColumn('iol_recommendations', 'decided_at')) {
                $table->timestamp('decided_at')->nullable()->after('decided_by_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('iol_recommendations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('iol_item_id');
            $table->dropConstrainedForeignId('decided_by_id');
            $table->dropColumn([
                'formula', 'a_constant', 'target_refraction',
                'predicted_refraction', 'is_final', 'decided_at',
            ]);
        });

        if (Schema::hasColumn('iol_items', 'a_constant')) {
            Schema::table('iol_items', function (Blueprint $table) {
                $table->dropColumn('a_constant');
            });
        }
    }
};
