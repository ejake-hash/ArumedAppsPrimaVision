<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * IOL: model stok PER-TIPE (bukan per-unit).
 *
 *  - Tambah kolom `gtin` (VARCHAR 14) untuk lookup cepat saat scan DataMatrix/UDI.
 *    Index NON-UNIQUE: satu GTIN = satu tipe IOL, tapi punya banyak batch/unit fisik.
 *  - DROP partial unique index `iol_items_serial_number_unique`: serial bukan lagi
 *    identitas master; serial/lot dicatat per pemakaian di `surgery_iol_usage`.
 *
 * Kolom legacy `stock`/`is_used` SENGAJA TIDAK di-drop (backward-compat; di-deprecate;
 * sumber stok sebenarnya = `inventory_stocks`).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempoten: aman di-run ulang / migrate:refresh (kolom & index dijaga hasColumn).
        if (! Schema::hasColumn('iol_items', 'gtin')) {
            Schema::table('iol_items', function (Blueprint $table) {
                $table->string('gtin', 14)->nullable()->after('gs1_barcode');
                $table->index('gtin', 'iol_items_gtin_index');
            });
        }

        // Per-tipe: serial tak lagi unik di master.
        DB::statement('DROP INDEX IF EXISTS iol_items_serial_number_unique');

        // Backfill ringan: isi gtin dari gs1_barcode bila gs1_barcode berisi 14 digit
        // murni (kasus paling umum saat admin hanya menempel GTIN polos). String UDI
        // lengkap dibiarkan NULL — akan terisi via scan/edit.
        DB::statement("UPDATE iol_items SET gtin = gs1_barcode
            WHERE gtin IS NULL AND gs1_barcode ~ '^[0-9]{14}$'");
    }

    public function down(): void
    {
        Schema::table('iol_items', function (Blueprint $table) {
            $table->dropIndex('iol_items_gtin_index');
            $table->dropColumn('gtin');
        });

        // Pulihkan partial unique serial (kondisi sebelum migrasi ini).
        DB::statement('CREATE UNIQUE INDEX iol_items_serial_number_unique
            ON iol_items (serial_number)
            WHERE serial_number IS NOT NULL AND deleted_at IS NULL');
    }
};
