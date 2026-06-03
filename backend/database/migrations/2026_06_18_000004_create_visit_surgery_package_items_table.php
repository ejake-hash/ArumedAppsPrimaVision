<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Komponen snapshot paket pasien. Hanya PROCEDURE / BHP / IOL (TANPA MEDICATION —
 * obat tetap lewat resep / obat-pulang). `unit_price` di-resolve dari Buku Tarif
 * per penjamin (getPrice) saat snapshot dibuat; basis diskon dihitung LIVE saat
 * consolidate kasir, jadi unit_price di sini untuk tampilan/referensi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visit_surgery_package_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_surgery_package_id')->constrained('visit_surgery_packages')->cascadeOnDelete();
            $table->string('item_type', 20);                 // PROCEDURE | BHP | IOL
            $table->uuid('item_id');
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['visit_surgery_package_id', 'item_type'], 'vsp_items_pkg_type_idx');
            $table->index(['item_type', 'item_id'], 'vsp_items_type_item_idx');
            $table->unique(['visit_surgery_package_id', 'item_type', 'item_id'], 'vsp_items_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_surgery_package_items');
    }
};
