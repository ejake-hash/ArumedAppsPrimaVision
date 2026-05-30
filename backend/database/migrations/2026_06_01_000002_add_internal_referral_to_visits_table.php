<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rujukan internal antar-poli (mis. Poli Mata Umum → Poli Retina).
     * Pola "visit anak": dokter A merujuk → dibuat Visit baru utk dokter B,
     * ditautkan ke visit induk via parent_visit_id. Tiap visit tetap 1:1 dengan
     * doctor_examination & billing_invoice (constraint UNIK existing tidak diubah).
     * Untuk BPJS poli-berbeda, visit anak punya SEP/klaim sendiri.
     */
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            // Visit anak menunjuk visit induk. Induk dihapus → tautan dilepas (NULL),
            // visit anak tetap berdiri sendiri (riwayat klinis tidak ikut terhapus).
            $table->foreignUuid('parent_visit_id')
                ->nullable()
                ->after('id')
                ->constrained('visits')
                ->nullOnDelete();

            // Jadwal dokter/poli ASAL rujukan (untuk audit & label "Rujukan dari Poli X").
            $table->foreignUuid('internal_referral_from_schedule_id')
                ->nullable()
                ->after('parent_visit_id')
                ->constrained('doctor_schedules')
                ->nullOnDelete();

            $table->string('internal_referral_reason', 255)
                ->nullable()
                ->after('internal_referral_from_schedule_id');

            $table->index('parent_visit_id');
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropForeign(['parent_visit_id']);
            $table->dropForeign(['internal_referral_from_schedule_id']);
            $table->dropIndex(['parent_visit_id']);
            $table->dropColumn([
                'parent_visit_id',
                'internal_referral_from_schedule_id',
                'internal_referral_reason',
            ]);
        });
    }
};
