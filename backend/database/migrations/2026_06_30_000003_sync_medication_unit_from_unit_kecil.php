<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill satuan obat: kolom `unit` (lama) vs `unit_kecil` (baru).
 *
 * Sejak migrasi 2026_05_26 (unit_besar/unit_kecil/konversi), form master obat HANYA
 * mengedit unit_kecil — kolom `unit` lama tak pernah ikut berubah. Namun seluruh modul
 * Farmasi (tab Stok, dispensing, verifikasi, ranap, POS, export opname, resep DokterView)
 * menampilkan kolom `unit` lama. Akibatnya mengubah "Satuan" di master tidak pernah
 * tercermin di Farmasi (mis. master "Flash" tapi stok gudang masih "Strip").
 *
 * Migrasi ini menyelaraskan data LAMA: set unit = unit_kecil untuk baris yang punya
 * unit_kecil terisi dan berbeda dari unit. Tulisan BARU dijaga oleh hook saving() di
 * model Medication yang mencerminkan unit_kecil → unit pada setiap simpan.
 *
 * Idempoten: hanya menyentuh baris yang unit_kecil-nya non-kosong & beda dari unit.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('medications')
            ->whereNotNull('unit_kecil')
            ->where('unit_kecil', '<>', '')
            ->whereColumn('unit_kecil', '<>', DB::raw('COALESCE(unit, \'\')'))
            ->update(['unit' => DB::raw('unit_kecil')]);
    }

    public function down(): void
    {
        // Tidak di-reverse: nilai `unit` lama yang berbeda dari unit_kecil tidak disimpan,
        // dan unit_kecil adalah sumber kebenaran satuan setelah migrasi 2026_05_26.
    }
};
