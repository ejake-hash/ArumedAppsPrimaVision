<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * surgery_anesthesia_reports — Laporan Anestesi terstruktur (RM 5.2), hal 1-2.
 *
 * 1 baris per operasi (unique surgery_record_id). Field terstruktur form 3
 * halaman disimpan di JSONB `form_data` (banyak & berkembang); beberapa kolom
 * kunci diekstrak untuk query/cetak cepat. Hal 3 (grafik vital durante) TIDAK
 * di sini — pakai surgery_anesthesia_vitals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surgery_anesthesia_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('surgery_record_id')->unique()->constrained('surgery_records')->cascadeOnDelete();
            $table->foreignUuid('visit_id')->nullable()->constrained('visits')->nullOnDelete();

            // Kolom kunci ringkas (diekstrak dari form_data utk query/cetak).
            $table->string('asa_class', 10)->nullable();
            $table->jsonb('teknik_anestesi')->nullable();   // array teknik terpilih

            // Payload lengkap field hal 1-2.
            $table->jsonb('form_data')->nullable();

            $table->foreignUuid('recorded_by_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('visit_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surgery_anesthesia_reports');
    }
};
