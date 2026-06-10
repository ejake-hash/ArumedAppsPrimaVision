<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Izinkan BANYAK tarif jual per (paket, penjamin) — varian harga dlm 1 penjamin.
 *
 * Sebelumnya unique (surgery_package_id, insurer_id) memaksa 1 tarif per penjamin.
 * Kebutuhan: 1 paket bedah boleh punya >1 varian harga utk penjamin yang sama
 * (mis. UMUM → "Phaco Mandalika", UMUM → "Phaco Osaka"), dibedakan display_name.
 *
 * Unique diturunkan jadi index biasa (lookup tetap cepat). Pemilihan varian saat
 * billing dilakukan eksplisit via surgery_package_tariff_id (lihat migrasi 000002 +
 * KasirService::resolvePackageTariff). Tanpa pilihan eksplisit, resolver memakai
 * varian default deterministik (display_name NULLS FIRST, created_at).
 *
 * AMAN: tidak menghapus data; hanya melonggarkan constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surgery_package_tariffs', function (Blueprint $t) {
            $t->dropUnique('surgery_package_tariffs_uniq');
            $t->index(['surgery_package_id', 'insurer_id'], 'surgery_package_tariffs_pkg_insurer_idx');
        });
    }

    public function down(): void
    {
        // Re-tighten: dedup dulu agar tak tabrakan unique (sisakan 1 baris per
        // (paket, insurer) — non-deleted, terbaru menang), lalu pulihkan unique.
        $rows = \Illuminate\Support\Facades\DB::table('surgery_package_tariffs')
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->get(['id', 'surgery_package_id', 'insurer_id']);

        $seen = [];
        $deleteIds = [];
        foreach ($rows as $row) {
            $key = $row->surgery_package_id . '|' . ($row->insurer_id ?? 'NULL');
            if (isset($seen[$key])) {
                // baris terbaru (urut created_at asc) menggantikan yang lebih lama
                $deleteIds[] = $seen[$key];
            }
            $seen[$key] = $row->id;
        }
        if (! empty($deleteIds)) {
            \Illuminate\Support\Facades\DB::table('surgery_package_tariffs')->whereIn('id', $deleteIds)->delete();
        }

        Schema::table('surgery_package_tariffs', function (Blueprint $t) {
            $t->dropIndex('surgery_package_tariffs_pkg_insurer_idx');
            $t->unique(['surgery_package_id', 'insurer_id'], 'surgery_package_tariffs_uniq');
        });
    }
};
