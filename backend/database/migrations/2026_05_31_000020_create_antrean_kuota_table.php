<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kuota antrean per poli/dokter/tanggal — sumber data field BPJS Antrol:
 *   kuotajkn, sisakuotajkn, kuotanonjkn, sisakuotanonjkn  (Docs/Antrol.md:255-260).
 *
 * Sisa kuota TIDAK disimpan (dihitung runtime = kuota - antrean terpakai hari itu,
 * lihat AntreanKuotaService::sisa). Tabel ini hanya menyimpan PLAFON.
 *
 * Resolusi kuota (paling spesifik menang) di service:
 *   1) baris (poli_code + employee_id + tanggal)
 *   2) baris (poli_code + employee_id, tanggal NULL = default mingguan)
 *   3) baris (poli_code, employee_id NULL = default poli)
 *   4) fallback config global.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('antrean_kuota', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('poli_code', 10);                 // poli lokal (DoctorSchedule.poli_code)
            $table->foreignUuid('employee_id')->nullable()   // dokter; NULL = default seluruh poli
                ->constrained('employees')->nullOnDelete();
            $table->date('tanggal')->nullable();             // tanggal spesifik; NULL = default berlaku tiap hari
            $table->unsignedInteger('kuota_jkn')->default(0);
            $table->unsignedInteger('kuota_nonjkn')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['poli_code', 'employee_id', 'tanggal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('antrean_kuota');
    }
};
