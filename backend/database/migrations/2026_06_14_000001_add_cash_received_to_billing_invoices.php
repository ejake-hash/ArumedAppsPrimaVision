<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * cash_received = uang TUNAI fisik yang diserahkan pasien (>= paid_amount tunai).
 * paid_amount sengaja di-clamp ke total (untuk logika partial-pay), jadi kembalian
 * tak bisa dihitung dari paid_amount. Simpan uang fisik agar kwitansi cetak bisa
 * menampilkan kembalian yang benar. Nullable: hanya terisi saat metode CASH.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table) {
            $table->decimal('cash_received', 14, 2)->nullable()->after('paid_amount');
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table) {
            $table->dropColumn('cash_received');
        });
    }
};
