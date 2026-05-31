<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 8 (Bedah Elektif Rawat Inap & Inap Observasi) — penanda alur masuk inap.
 *
 * - visits.inpatient_reason  : alasan dokter mengirim pasien ke rawat inap.
 *     OBSERVASI = inap pemeriksaan/observasi tanpa operasi (planning RAWAT_INAP).
 *     PRE_OP    = inap karena operasi (planning BEDAH + perlu rawat inap, datang H-1).
 *   Dibaca admisi (8B) & papan "Menunggu Kamar" / RANAP supaya petugas tahu konteks.
 *
 * - surgery_schedules.requires_inpatient : jadwal bedah ini butuh rawat inap (pre-op H-1).
 *   Ditandai dokter di planning Tab 4; dipakai admisi (8B) untuk mengenali pasien
 *   yang harus diopname & RANAP (8C) untuk merangkai bedah dari jadwal yang sama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            // OBSERVASI | PRE_OP (null = bukan kandidat inap dari dokter).
            $table->string('inpatient_reason', 20)->nullable()->after('jenis_pelayanan');
        });

        Schema::table('surgery_schedules', function (Blueprint $table) {
            $table->boolean('requires_inpatient')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropColumn('inpatient_reason');
        });

        Schema::table('surgery_schedules', function (Blueprint $table) {
            $table->dropColumn('requires_inpatient');
        });
    }
};
