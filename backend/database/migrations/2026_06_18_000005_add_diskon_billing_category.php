<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Kategori tagihan "Diskon" untuk grouping baris DISKON_PAKET (potongan paket
 * pasien) di kwitansi. sort_order 950 → muncul sebelum "Lainnya" (999), setelah
 * semua komponen tagih. Idempoten (skip bila sudah ada).
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('billing_categories')->whereRaw('LOWER(name) = ?', ['diskon'])->exists();
        if (! $exists) {
            DB::table('billing_categories')->insert([
                'id'         => (string) Str::uuid(),
                'name'       => 'Diskon',
                'sort_order' => 950,
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('billing_categories')->whereRaw('LOWER(name) = ?', ['diskon'])->delete();
    }
};
