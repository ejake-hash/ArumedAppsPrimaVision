<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Kategori tagihan sub-BHP untuk grouping kwitansi: "BAHAN HABIS PAKAI" (MEDICAL_BHP),
 * "CSSD", "INSTRUMENT" (INSTRUMENT_SET). Disisipkan di sekitar pos "BHP" lama (sort 70)
 * supaya berurutan & konsisten dgn Buku Tarif (yang sudah memetakan kategori BHP item
 * ke label ini). KasirService::buildBhpLines/buildPaketBhpLines kini set category =
 * sub-kategori item BHP → kwitansi pecah per seksi, bukan satu "BHP" datar.
 *
 * "BHP" lama digeser ke 76 → tetap jadi fallback untuk item tanpa kategori. Idempoten
 * (upsert by name, case-insensitive). Selaras pola seed pos obat (2026_06_18_000007).
 */
return new class extends Migration
{
    private array $rows = [
        ['name' => 'BAHAN HABIS PAKAI', 'sort_order' => 70],
        ['name' => 'CSSD',              'sort_order' => 72],
        ['name' => 'INSTRUMENT',        'sort_order' => 74],
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

        // Geser pos "BHP" lama ke 76 (di bawah 3 sub-kategori) — fallback item tanpa kategori.
        DB::table('billing_categories')->whereRaw('LOWER(name) = ?', ['bhp'])
            ->update(['sort_order' => 76, 'updated_at' => now()]);
    }

    public function down(): void
    {
        foreach ($this->rows as $r) {
            DB::table('billing_categories')->whereRaw('LOWER(name) = ?', [strtolower($r['name'])])->delete();
        }
        // Kembalikan "BHP" ke sort_order 70.
        DB::table('billing_categories')->whereRaw('LOWER(name) = ?', ['bhp'])
            ->update(['sort_order' => 70, 'updated_at' => now()]);
    }
};
