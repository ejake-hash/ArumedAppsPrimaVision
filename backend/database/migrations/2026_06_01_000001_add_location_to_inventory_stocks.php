<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah dimensi LOKASI ke inventory_stocks.
 *
 * Sebelumnya stok per-batch tanpa lokasi → tidak ada pemisahan gudang vs unit.
 * Sekarang 3 lokasi: INVENTORI (gudang induk), BEDAH, FARMASI. Stok berpindah
 * antar lokasi lewat alur Request Unit (deliver = transfer). Data existing
 * dianggap milik gudang → diisi 'INVENTORI'.
 *
 * Unique constraint diperluas dengan lokasi: batch yang sama boleh ada di lebih
 * dari satu lokasi (mis. batch X di INVENTORI dan di FARMASI setelah transfer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_stocks', function (Blueprint $table) {
            $table->string('location', 20)->default('INVENTORI')->after('item_type');
        });

        // Pastikan baris lama eksplisit INVENTORI (jaga-jaga bila ada null).
        DB::table('inventory_stocks')->whereNull('location')->update(['location' => 'INVENTORI']);

        Schema::table('inventory_stocks', function (Blueprint $table) {
            $table->dropUnique('inventory_stocks_item_batch_unique');
            $table->unique(['location', 'item_type', 'item_id', 'batch_no'], 'inventory_stocks_loc_item_batch_unique');
            $table->index(['location', 'item_type', 'item_id'], 'inventory_stocks_loc_item_idx');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_stocks', function (Blueprint $table) {
            $table->dropUnique('inventory_stocks_loc_item_batch_unique');
            $table->dropIndex('inventory_stocks_loc_item_idx');
            $table->dropColumn('location');
        });

        Schema::table('inventory_stocks', function (Blueprint $table) {
            $table->unique(['item_type', 'item_id', 'batch_no'], 'inventory_stocks_item_batch_unique');
        });
    }
};
