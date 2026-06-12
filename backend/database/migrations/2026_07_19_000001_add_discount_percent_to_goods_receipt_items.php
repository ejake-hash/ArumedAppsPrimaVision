<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Diskon PER ITEM (persen) di penerimaan/GRN. Menggantikan diskon per-faktur
 * (kolom header goods_receipts.discount_amount tetap ada, kini menyimpan AGREGAT
 * diskon per-item untuk pelaporan). Subtotal baris disimpan NET (sudah dipotong).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipt_items', function (Blueprint $table) {
            $table->decimal('discount_percent', 5, 2)->default(0)->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipt_items', function (Blueprint $table) {
            $table->dropColumn('discount_percent');
        });
    }
};
