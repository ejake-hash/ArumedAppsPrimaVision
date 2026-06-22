<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Item tambahan yang diinput KASIR (tindakan/obat/BHP/IOL/alat medis lewat "Edit
 * Tagihan") tidak punya sumber resmi (visit_services/prescriptions/snapshot paket),
 * sehingga HILANG tiap tagihan dibangun ulang (reconsolidateInvoice yang dipicu
 * Dokter/Farmasi/Bedah/Perawat, atau Batalkan & Susun Ulang). Dua kolom:
 *
 *  - is_kasir_manual : tandai baris input kasir → dipertahankan apa adanya saat
 *    rebuild (builder tak meregenerasinya karena tak ada sumber → tak dobel).
 *  - consumed_batches: rincian batch stok FARMASI yang dipotong saat input (BHP),
 *    untuk PENGEMBALIAN stok presisi (batch + expiry) bila baris dihapus.
 *
 * Catatan obat: baris OBAT kasir tetap is_kasir_manual=false karena diregenerasi
 * buildObatLines via resep (DISPENSED); stok dikembalikan per-qty lewat resepnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_items', function (Blueprint $table) {
            $table->boolean('is_kasir_manual')->default(false)->after('paket_excluded');
            $table->json('consumed_batches')->nullable()->after('is_kasir_manual');
        });
    }

    public function down(): void
    {
        Schema::table('billing_items', function (Blueprint $table) {
            $table->dropColumn(['is_kasir_manual', 'consumed_batches']);
        });
    }
};
