<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * surgery_iol_usage: audit barcode IOL yang DITANAM + jaring pengaman idempotensi.
 *
 *  - `gtin` + `gs1_barcode`: rekam jejak hasil scan UDI tepat (traceability/recall).
 *  - `expiry_date`: tanggal kedaluwarsa lensa yang ditanam (dari label).
 *  - `iol_item_id` → NULLABLE: lensa non-master (belum terdaftar) tetap boleh dicatat
 *    (kebijakan "peringatkan, bukan tolak"). FK direstore tetap restrictOnDelete.
 *  - UNIQUE partial (surgery_record_id, eye_side): 1 lensa per mata per operasi.
 *    App-level sudah pakai updateOrCreate; ini jaring pengaman DB. Partial agar
 *    baris soft-deleted tidak ikut menghalangi.
 *
 * Idempoten: tiap perubahan dijaga "bila belum ada" agar aman dijalankan ulang
 * (kolom mungkin sudah dibuat di sesi sebelumnya saat file ini sempat hilang).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surgery_iol_usage', function (Blueprint $table) {
            if (! Schema::hasColumn('surgery_iol_usage', 'gtin')) {
                $table->string('gtin', 14)->nullable()->after('serial_number');
            }
            if (! Schema::hasColumn('surgery_iol_usage', 'gs1_barcode')) {
                $table->string('gs1_barcode', 512)->nullable()->after('gtin');
            }
            if (! Schema::hasColumn('surgery_iol_usage', 'expiry_date')) {
                $table->date('expiry_date')->nullable()->after('gs1_barcode');
            }
        });

        // iol_item_id → nullable (drop FK → nullable → re-add FK restrictOnDelete).
        try {
            Schema::table('surgery_iol_usage', function (Blueprint $table) {
                $table->dropForeign(['iol_item_id']);
            });
        } catch (\Throwable $e) { /* FK mungkin sudah dilepas */ }
        DB::statement('ALTER TABLE surgery_iol_usage ALTER COLUMN iol_item_id DROP NOT NULL');
        try {
            Schema::table('surgery_iol_usage', function (Blueprint $table) {
                $table->foreign('iol_item_id')->references('id')->on('iol_items')->restrictOnDelete();
            });
        } catch (\Throwable $e) { /* FK mungkin sudah ada */ }

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS surgery_iol_usage_record_eye_unique
            ON surgery_iol_usage (surgery_record_id, eye_side)
            WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS surgery_iol_usage_record_eye_unique');

        // Pulihkan NOT NULL (hanya aman bila tak ada baris null — uji/dev).
        try {
            Schema::table('surgery_iol_usage', function (Blueprint $table) {
                $table->dropForeign(['iol_item_id']);
            });
        } catch (\Throwable $e) { /* noop */ }
        DB::statement('ALTER TABLE surgery_iol_usage ALTER COLUMN iol_item_id SET NOT NULL');
        Schema::table('surgery_iol_usage', function (Blueprint $table) {
            $table->foreign('iol_item_id')->references('id')->on('iol_items')->restrictOnDelete();
        });

        Schema::table('surgery_iol_usage', function (Blueprint $table) {
            $table->dropColumn(['gtin', 'gs1_barcode', 'expiry_date']);
        });
    }
};
