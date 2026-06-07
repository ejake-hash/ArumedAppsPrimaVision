<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paket Obat Pasca-Bedah — template resep rutin yang dipakai lintas jenis operasi.
 * Master ringan TERPISAH dari paket bedah (surgery_packages): kolomnya mencerminkan
 * prescription_items (dose/frequency/route/duration_days) supaya auto-fill ke resep
 * pasca-bedah lurus. Penyerapan ke harga paket diputuskan saat resep dibuat via
 * is_bedah (lihat BedahService::storePostOpPrescription), bukan di tabel ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prescription_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('category', 100)->nullable(); // label jenis operasi (mis. "Pasca Phaco")
            $table->string('description', 1000)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
        });

        Schema::create('prescription_template_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('prescription_template_id')->constrained('prescription_templates')->cascadeOnDelete();
            $table->foreignUuid('medication_id')->constrained('medications')->restrictOnDelete();
            $table->integer('quantity')->default(1);
            $table->string('dose', 100)->nullable();
            $table->string('frequency', 100)->nullable();
            $table->string('route', 100)->nullable();
            $table->integer('duration_days')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('prescription_template_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prescription_template_items');
        Schema::dropIfExists('prescription_templates');
    }
};
