<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * prescription_items: varian kemasan jual terpilih (di-set Farmasi saat verifikasi).
 *
 *  - sale_unit_id  → medication_sale_units (NULL = satuan kecil/perilaku lama).
 *  - sale_unit_qty = jumlah KEMASAN.
 *  - INVARIAN (dijaga service): quantity (satuan kecil, sumber kebenaran STOK)
 *    = sale_unit_qty × isi kemasan → dispensing/assert stok TIDAK berubah.
 *  - restrictOnDelete: CRUD kemasan hanya soft-delete; FK menjaga hard-delete liar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prescription_items', function (Blueprint $table) {
            $table->foreignUuid('sale_unit_id')->nullable()
                ->constrained('medication_sale_units')->restrictOnDelete();
            $table->unsignedInteger('sale_unit_qty')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('prescription_items', function (Blueprint $table) {
            $table->dropForeign(['sale_unit_id']);
            $table->dropColumn(['sale_unit_id', 'sale_unit_qty']);
        });
    }
};
