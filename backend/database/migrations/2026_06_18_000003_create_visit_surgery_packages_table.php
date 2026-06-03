<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot paket pasien (header) — 1 baris per visit.
 *
 * Saat dokter pilih paket (bedah di planning / pemeriksaan di Tab Tindakan),
 * komponen paket master di-COPY jadi snapshot milik pasien ini. Snapshot adalah
 * LAPISAN METADATA HARGA untuk hitung diskon paket di kwitansi — BUKAN sumber
 * tagih. Komponen tetap ditagih dari sumber aktual (visitServices / used_qty /
 * iolUsages); kasir menambah 1 baris diskon = Σ(komponen snapshot) − sell_price.
 *
 * `label` = redaksi baris diskon di kwitansi (editable). softDeletes agar
 * replace saat ganti paket aman (pola SurgeryPackageTariff).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visit_surgery_packages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->constrained('visits')->cascadeOnDelete();
            $table->foreignUuid('surgery_schedule_id')->nullable()->constrained('surgery_schedules')->nullOnDelete();
            $table->foreignUuid('source_surgery_package_id')->nullable()->constrained('surgery_packages')->nullOnDelete();
            $table->string('package_type', 20)->default('BEDAH');     // BEDAH | PEMERIKSAAN
            $table->string('package_name', 255);
            $table->string('package_code', 100)->nullable();
            $table->decimal('sell_price', 14, 2)->default(0);          // harga jual paket utk penjamin pasien
            $table->decimal('total_base_price', 14, 2)->default(0);    // Σ(qty × unit_price) item snapshot
            $table->string('label', 150)->nullable();                  // redaksi baris diskon di kwitansi
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            // 1 snapshot aktif per visit (unique tetap walau soft-delete dipakai;
            // service pakai pola withTrashed→restore untuk hindari 23505).
            $table->unique('visit_id', 'visit_surgery_packages_visit_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_surgery_packages');
    }
};
