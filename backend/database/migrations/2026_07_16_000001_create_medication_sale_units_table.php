<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * medication_sale_units — VARIAN KEMASAN JUAL per obat (per Strip / Box, dst.).
 *
 * Satu obat bisa dijual ke pasien dalam kemasan berbeda dengan HARGA INDEPENDEN
 * per kemasan (bukan kelipatan harga satuan). Kemasan DASAR (satuan kecil, isi=1)
 * TIDAK disimpan di sini — tetap medication_tariffs.price → seluruh asumsi lama
 * (getPrice, posMap, CSV, dst.) tak tersentuh; tabel ini lapisan opsional.
 *
 *  - insurer_id NULL = berlaku SEMUA penjamin; baris ber-insurer = override.
 *    Resolusi billing: baris insurer persis → baris NULL (label sama).
 *  - isi = jumlah satuan kecil per kemasan (stok tetap dihitung satuan kecil:
 *    prescription_items.quantity = sale_unit_qty × isi).
 *  - UNIQUE partial ganda (Postgres: NULL ≠ NULL, unique biasa bolos utk insurer
 *    NULL yang justru kasus dominan). TANPA filter deleted_at — upsert
 *    soft-delete-aware me-restore baris trashed (pola upsertTarifRow).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medication_sale_units', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('medication_id')->constrained('medications')->cascadeOnDelete();
            $table->foreignUuid('insurer_id')->nullable()->constrained('insurers')->nullOnDelete();
            $table->string('label', 50);            // 'Strip', 'Box', 'Botol', ...
            $table->unsignedInteger('isi');         // satuan kecil per kemasan (>=1, divalidasi app)
            $table->decimal('price', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('medication_id');
        });

        DB::statement('CREATE UNIQUE INDEX medication_sale_units_med_label_null_uniq
            ON medication_sale_units (medication_id, label)
            WHERE insurer_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX medication_sale_units_med_insurer_label_uniq
            ON medication_sale_units (medication_id, insurer_id, label)
            WHERE insurer_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('medication_sale_units');
    }
};
