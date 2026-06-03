<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda EKSPLISIT "penjamin ini TPA induk" (is_tpa).
 *
 * Sebelumnya "apakah TPA induk" disimpulkan dari punya-anak (children_count>0).
 * Dengan flag eksplisit, admin bisa menandai sebuah asuransi sebagai TPA induk
 * (mis. Admedika) walau belum punya anggota — barulah panel "Anggota TPA" muncul.
 *
 * BACKFILL: insurer yang SUDAH punya anak (induk de-facto) ditandai is_tpa=true
 * agar TPA lama tidak kehilangan panel kelola setelah aturan baru.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('insurers', function (Blueprint $table) {
            $table->boolean('is_tpa')->default(false)->after('is_system');
            $table->index('is_tpa');
        });

        // Backfill: setiap insurer yang punya minimal satu anak = TPA induk de-facto.
        $parentIds = DB::table('insurers')
            ->whereNotNull('parent_id')
            ->whereNull('deleted_at')
            ->distinct()
            ->pluck('parent_id')
            ->all();

        if (! empty($parentIds)) {
            DB::table('insurers')->whereIn('id', $parentIds)->update(['is_tpa' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('insurers', function (Blueprint $table) {
            $table->dropIndex(['is_tpa']);
            $table->dropColumn('is_tpa');
        });
    }
};
