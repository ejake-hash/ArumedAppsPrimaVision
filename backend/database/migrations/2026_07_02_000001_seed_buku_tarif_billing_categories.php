<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sinkronkan master Kategori Tagihan (billing_categories) dengan kategori Buku Tarif
 * (procedure_categories) supaya baris TINDAKAN di kwitansi terkelompok benar.
 *
 * Sebelumnya buildTindakanLines() memakai procedure->category apa adanya (mis.
 * "Tindakan Dokter", "Laboratorium", "Sewa Kamar") yang TIDAK ada di master
 * billing_categories → semua jatuh ke bucket "Lainnya" saat grouping kwitansi.
 * Solusi (keputusan user): section kwitansi mengikuti kategori Buku Tarif PERSIS,
 * jadi cukup daftarkan nama-nama itu sebagai billing_categories (bukan remap).
 *
 * Idempoten (upsert by name) → aman di DB yang sudah terisi via SQL manual
 * (dev/live saat go-live), dan mengisi otomatis di instalasi DB baru.
 */
return new class extends Migration
{
    private array $rows = [
        ['name' => 'Tarif Administrasi',                    'sort_order' => 12],
        ['name' => 'Konsultasi Dokter',                     'sort_order' => 22],
        ['name' => 'Pemeriksaan Dasar Rutin',               'sort_order' => 31],
        ['name' => 'Pemeriksaan Dasar Lainnya',             'sort_order' => 32],
        ['name' => 'Pemeriksaan Penunjang Diagnostik Mata', 'sort_order' => 33],
        ['name' => 'Tindakan Dokter',                       'sort_order' => 41],
        ['name' => 'Tindakan Perawatan dan Kefarmasian',    'sort_order' => 43],
        ['name' => 'Sewa Kamar',                            'sort_order' => 46],
        ['name' => 'Laboratorium',                          'sort_order' => 51],
        ['name' => 'Radiologi',                             'sort_order' => 52],
        ['name' => 'Sewa Peralatan Medik',                  'sort_order' => 91],
    ];

    public function up(): void
    {
        foreach ($this->rows as $r) {
            $existing = DB::table('billing_categories')->whereRaw('LOWER(name) = ?', [strtolower($r['name'])])->first();
            if ($existing) {
                DB::table('billing_categories')->where('id', $existing->id)->update([
                    'sort_order' => $r['sort_order'],
                    'is_active'  => true,
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('billing_categories')->insert([
                    'id'         => (string) Str::uuid(),
                    'name'       => $r['name'],
                    'sort_order' => $r['sort_order'],
                    'is_active'  => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->rows as $r) {
            DB::table('billing_categories')->whereRaw('LOWER(name) = ?', [strtolower($r['name'])])->delete();
        }
    }
};
