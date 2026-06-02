<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tambah kategori tagihan RANAP: "Kamar Rawat Inap" & "Visite Dokter".
 *
 * KasirService::buildInpatientChargeLines memberi label category 'Kamar Rawat Inap'
 * (TYPE_ROOM) & 'Visite Dokter' (TYPE_VISITE) pada baris invoice. Tanpa entri master
 * billing_categories yang namanya SAMA PERSIS, KasirView mengelompokkannya ke grup
 * fallback "Lainnya". Migrasi ini menambah 2 kategori itu (idempotent: skip jika sudah
 * ada by name) dengan sort_order di antara Tindakan(40) dan Penunjang(50).
 */
return new class extends Migration
{
    private array $rows = [
        ['Kamar Rawat Inap', 42],
        ['Visite Dokter',    44],
    ];

    public function up(): void
    {
        $now = now();
        foreach ($this->rows as [$name, $order]) {
            $exists = DB::table('billing_categories')->where('name', $name)->exists();
            if ($exists) {
                continue;
            }
            DB::table('billing_categories')->insert([
                'id'         => (string) Str::uuid(),
                'name'       => $name,
                'sort_order' => $order,
                'is_active'  => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Hapus hanya bila belum dipakai? billing_categories cuma referensi tampilan
        // (item invoice simpan string category, bukan FK) → aman dihapus by name.
        DB::table('billing_categories')
            ->whereIn('name', array_column($this->rows, 0))
            ->delete();
    }
};
