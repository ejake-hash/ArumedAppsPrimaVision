<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Balance cairan (intake/output) pasien rawat inap — STARKES PAP (kondisional).
 * Catatan per-event: arah (masuk/keluar), kategori, volume (ml), jam, pencatat.
 * Saldo dihitung saat baca (intake − output).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fluid_balance_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->constrained('visits')->cascadeOnDelete();
            $table->timestamp('recorded_at');
            $table->string('direction');           // INTAKE | OUTPUT
            $table->string('category')->nullable(); // oral/infus/transfusi/obat IV — urin/drain/muntah/BAB/IWL
            $table->integer('volume_ml');
            $table->foreignUuid('recorded_by_id')->nullable()
                ->constrained('employees')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['visit_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fluid_balance_records');
    }
};
