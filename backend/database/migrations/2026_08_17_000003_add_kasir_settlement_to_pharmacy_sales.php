<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penjualan obat bebas "Tagih ke Kasir" — jalur handoff Farmasi → Kasir.
 *
 * Farmasi menyiapkan keranjang (stok dipotong = reserve) lalu mengirim tagihan
 * ke Kasir dengan status PENDING (belum bayar). Kasir menerima pembayaran →
 * status PAID, mencatat kasir & waktu settle, lalu menerbitkan kwitansi.
 *
 * Kolom pembayaran (payment_method/paid_amount/change_amount) sudah ada &
 * default 0 → PENDING dibuat tanpa nilai bayar; diisi saat Kasir menutup.
 *
 *   channel        — FARMASI (bayar langsung di apotek) | KASIR (dibayar di kasir)
 *   settled_by_id  — pegawai kasir yang menutup pembayaran
 *   settled_at     — waktu pembayaran ditutup di kasir
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pharmacy_sales', function (Blueprint $table) {
            $table->string('channel', 10)->default('FARMASI')->after('status')->index();
            $table->foreignUuid('settled_by_id')->nullable()->after('sold_by_id')->constrained('employees')->nullOnDelete();
            $table->timestamp('settled_at')->nullable()->after('settled_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('pharmacy_sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('settled_by_id');
            $table->dropColumn(['channel', 'settled_at']);
        });
    }
};
