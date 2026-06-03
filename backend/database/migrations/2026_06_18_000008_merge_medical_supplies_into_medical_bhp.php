<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hapus kategori BHP "MEDICAL_SUPPLIES" — gabungkan semua item ke "MEDICAL_BHP"
 * (BHP Medis). Per keputusan: kategori Medical Supplies ditiadakan, isinya jadi BHP.
 *
 * Hanya menyentuh kolom kategori (bukan tarif/stok). Mengikutkan baris soft-deleted
 * agar konsisten. Irreversible secara semantik (item lama tak bisa dibedakan lagi),
 * down() = no-op informatif.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('bhp_items')
            ->where('category', 'MEDICAL_SUPPLIES')
            ->update(['category' => 'MEDICAL_BHP', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Tidak bisa memulihkan item mana yang dulunya MEDICAL_SUPPLIES (info hilang
        // setelah merge). Sengaja no-op.
    }
};
