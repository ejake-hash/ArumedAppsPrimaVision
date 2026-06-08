<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah informasi pembayaran & pajak pada penerimaan barang (faktur pembelian):
 *   - payment_method   : TUNAI | KREDIT (default TUNAI agar baris lama valid)
 *   - payment_term_days: jangka waktu kredit (hari) — hanya relevan saat KREDIT
 *   - due_date         : tanggal jatuh tempo = receipt_date + payment_term_days
 *   - discount_amount  : diskon header nominal Rp (atas Subtotal/total_amount)
 *   - ppn_percent      : persen PPN (mis. 11.00) atas DPP = Subtotal − Diskon
 *   - ppn_amount       : nilai PPN hasil hitung
 *   - grand_total      : DPP + PPN (yang ditagih supplier)
 *
 * `total_amount` lama TETAP = Σ subtotal item (Subtotal/basis nilai inventori) —
 * additive & prod-safe; baris lama otomatis grand_total = total_amount (diskon/ppn 0).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->string('payment_method', 20)->default('TUNAI')->after('invoice_number');
            $table->unsignedSmallInteger('payment_term_days')->nullable()->after('payment_method');
            $table->date('due_date')->nullable()->after('payment_term_days');
            $table->decimal('discount_amount', 16, 2)->default(0)->after('total_amount');
            $table->decimal('ppn_percent', 5, 2)->default(0)->after('discount_amount');
            $table->decimal('ppn_amount', 16, 2)->default(0)->after('ppn_percent');
            $table->decimal('grand_total', 16, 2)->default(0)->after('ppn_amount');
        });

        // Baris lama: grand_total = total_amount (belum ada diskon/ppn).
        DB::statement('UPDATE goods_receipts SET grand_total = total_amount WHERE grand_total = 0');
    }

    public function down(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->dropColumn([
                'payment_method',
                'payment_term_days',
                'due_date',
                'discount_amount',
                'ppn_percent',
                'ppn_amount',
                'grand_total',
            ]);
        });
    }
};
