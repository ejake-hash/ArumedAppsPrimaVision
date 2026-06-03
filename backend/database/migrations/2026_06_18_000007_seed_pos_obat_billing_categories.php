<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Kategori tagihan pos obat untuk grouping kwitansi: "Obat Pulang" / "Obat
 * Tindakan" / "Obat Injeksi". Disisipkan di sekitar pos "Obat" lama (sort 60)
 * supaya berurutan. "Obat" lama digeser ke 66 → jadi pos fallback umum
 * (baris invoice lama tetap valid). Idempoten (upsert by name).
 *
 * Sekalian bersihkan baris sampah uji "E2E_BILLKAT_DELETEME" (sisa test lama).
 */
return new class extends Migration
{
    private array $rows = [
        ['name' => 'Obat Pulang',   'sort_order' => 60],
        ['name' => 'Obat Tindakan', 'sort_order' => 62],
        ['name' => 'Obat Injeksi',  'sort_order' => 64],
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

        // Geser pos "Obat" lama ke 66 (di bawah 3 pos baru) — jadi fallback umum.
        DB::table('billing_categories')->whereRaw('LOWER(name) = ?', ['obat'])
            ->update(['sort_order' => 66, 'updated_at' => now()]);

        // Cleanup baris sampah uji.
        DB::table('billing_categories')->where('name', 'E2E_BILLKAT_DELETEME')->delete();
    }

    public function down(): void
    {
        foreach ($this->rows as $r) {
            DB::table('billing_categories')->whereRaw('LOWER(name) = ?', [strtolower($r['name'])])->delete();
        }
        // Kembalikan "Obat" ke sort_order 60.
        DB::table('billing_categories')->whereRaw('LOWER(name) = ?', ['obat'])
            ->update(['sort_order' => 60, 'updated_at' => now()]);
    }
};
