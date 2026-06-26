<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monitoring Kerjasama (PKS) — perjanjian kerjasama dengan asuransi/perusahaan.
 * Opsional menautkan ke master Insurer; partner_name jadi sumber utama label
 * (boleh diisi manual untuk mitra non-penjamin).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partnership_agreements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('insurer_id')->nullable(); // tautan ke master Asuransi/TPA (opsional)
            $table->string('partner_name');
            $table->string('partner_type', 20)->default('ASURANSI'); // ASURANSI/PERUSAHAAN/TPA/LAINNYA
            $table->string('pks_number')->nullable();
            $table->date('pks_start_date')->nullable();
            $table->date('addendum_date')->nullable();
            $table->date('pks_end_date')->nullable();
            $table->text('notes')->nullable();
            $table->string('pic_name')->nullable();
            $table->string('pic_phone', 30)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('insurer_id')->references('id')->on('insurers')->nullOnDelete();
            $table->index('partner_type');
            $table->index('pks_end_date');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partnership_agreements');
    }
};
