<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SPM (Standar Pelayanan/menit per pasien) per poli/dokter — dasar perhitungan
 * BPJS Antrol: estimasidilayani (antrean/add) & waktutunggu (Sisa Antrean).
 *   waktutunggu (detik) = (menit_per_pasien * 60) * (sisa antrean - 1)   (Docs/Antrol.md:859)
 *
 * Resolusi (paling spesifik menang) di AntreanSpmService:
 *   1) baris (poli_code + employee_id)
 *   2) baris (poli_code, employee_id NULL = default poli)
 *   3) fallback config global (default 15 menit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('antrean_spm', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('poli_code', 10);
            $table->foreignUuid('employee_id')->nullable()   // dokter; NULL = default seluruh poli
                ->constrained('employees')->nullOnDelete();
            $table->unsignedInteger('menit_per_pasien')->default(15);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['poli_code', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('antrean_spm');
    }
};
