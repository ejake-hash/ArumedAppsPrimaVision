<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dokter PELAKSANA per baris biaya (mis. konsultasi/tindakan dokter spesialis
     * di IGD). Beda dari created_by_id (= petugas yang menginput). Dipakai rekap
     * honor (KeuanganService) agar jasa medis mengalir ke dokter yang benar,
     * bukan jatuh ke DPJP kunjungan (Dokter Jaga IGD). Nullable: charge biasa
     * (room/obat/BHP) tak punya dokter pelaksana.
     */
    public function up(): void
    {
        Schema::table('inpatient_charges', function (Blueprint $table) {
            $table->foreignUuid('performed_by_id')->nullable()->after('created_by_id')
                ->constrained('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inpatient_charges', function (Blueprint $table) {
            $table->dropConstrainedForeignId('performed_by_id');
        });
    }
};
