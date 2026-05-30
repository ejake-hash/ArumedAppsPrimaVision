<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pemetaan poli lokal (doctor_schedules.poli_code: GLA/EKS/KAT/RET/...)
        // ke kode poli BPJS (dari /referensi/poli). Dipakai SEP (poli.tujuan) &
        // sinkron jadwal dokter ke Antrean. Diatur dari menu Jadwal Dokter.
        Schema::create('bpjs_poli_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('poli_code', 10)->unique();   // kode poli lokal Arumed
            $table->string('poli_name', 100)->nullable(); // nama poli lokal (info)
            $table->string('bpjs_poli_code', 10);          // kode poli BPJS
            $table->string('bpjs_poli_name', 150)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('bpjs_poli_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bpjs_poli_mappings');
    }
};
