<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * surgery_anesthesia_vitals — pencatatan tanda vital anestesi DURANTE operasi.
 *
 * Child per surgery_records (1 operasi = banyak baris vital ber-timestamp).
 * Diisi real-time tiap interval oleh penata/dokter anestesi (pola sama dengan
 * medical_equipment_usages: per-baris, ber-timestamp, recorded_by audit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surgery_anesthesia_vitals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('surgery_record_id')->constrained('surgery_records')->cascadeOnDelete();
            $table->timestamp('recorded_at')->useCurrent();

            // Parameter vital (nullable — tak semua kolom diisi tiap interval).
            $table->unsignedSmallInteger('td_sistol')->nullable();
            $table->unsignedSmallInteger('td_diastol')->nullable();
            $table->unsignedSmallInteger('nadi')->nullable();
            $table->decimal('spo2', 5, 2)->nullable();
            $table->unsignedSmallInteger('rr')->nullable();
            $table->unsignedSmallInteger('etco2')->nullable();
            $table->decimal('suhu', 4, 1)->nullable();
            $table->text('obat_kejadian')->nullable();

            $table->foreignUuid('recorded_by_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('surgery_record_id');
            $table->index('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surgery_anesthesia_vitals');
    }
};
