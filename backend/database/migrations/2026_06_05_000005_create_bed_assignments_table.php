<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Histori penempatan bed (basis billing room charge PER-PERIODE).
     * Tiap pindah kamar: assignment lama ditutup (released_at), baru dibuat.
     *  - kelas_rawat_hak  = snapshot kelas HAK pada periode ini → BASIS TARIF.
     *  - kelas_rawat_room = snapshot kelas room AKTUAL → audit "titip kelas".
     * reason: ADMISSION | TRANSFER (pindah sekelas) | TITIP_KELAS (hak tetap)
     *         | UPGRADE_KELAS / DOWNGRADE_KELAS (hak berubah → tarif ikut).
     */
    public function up(): void
    {
        Schema::create('bed_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->constrained('visits')->cascadeOnDelete();
            $table->foreignUuid('bed_id')->constrained('beds')->restrictOnDelete();
            $table->foreignUuid('room_id')->constrained('rooms')->restrictOnDelete();
            $table->string('kelas_rawat_hak', 5);            // snapshot kelas hak (basis tarif)
            $table->string('kelas_rawat_room', 5);           // snapshot kelas room aktual
            $table->timestamp('assigned_at');
            $table->timestamp('released_at')->nullable();    // null = periode aktif
            $table->foreignUuid('assigned_by_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('reason', 20)->default('ADMISSION');
            $table->timestamps();

            $table->index('visit_id');
            $table->index('bed_id');
            $table->index('released_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bed_assignments');
    }
};
