<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tarif kamar per-kelas per-penjamin (mirror procedure_tariffs).
     * Basis tarif = KELAS HAK (room_class), bukan kelas room aktual:
     * pasien hak Kelas 2 yang dititip di room Kelas 1 tetap ditagih Kelas 2.
     */
    public function up(): void
    {
        Schema::create('room_tariffs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('room_class', 5);             // KELAS hak sebagai key (1/2/3/VIP)
            $table->foreignUuid('insurer_id')->nullable()->constrained('insurers')->nullOnDelete();
            $table->string('classification', 20);        // UMUM / BPJS / ASURANSI / PERUSAHAAN / SOSIAL
            $table->decimal('price', 14, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['room_class', 'insurer_id', 'classification']);
            $table->index('classification');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_tariffs');
    }
};
