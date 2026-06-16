<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * eMAR — catatan pemberian obat ke pasien rawat inap (PKPO 4.3).
 *
 * Melengkapi alur resep RANAP yang berhenti di status DISPENSED ("diserahkan ke
 * ruangan"): tabel ini mencatat PEMBERIAN nyata ke pasien — jam, oleh perawat
 * siapa, status (diberikan/ditunda/dilewati) + alasan. Bukan tagihan (billing
 * tetap saat dispensing); ini dokumentasi klinis pemberian.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medication_administrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->constrained('visits')->cascadeOnDelete();
            // Sumber order (item resep RANAP yg sudah dispensed) — boleh null untuk
            // pemberian ad-hoc / obat floor-stock.
            $table->foreignUuid('prescription_item_id')->nullable()
                ->constrained('prescription_items')->nullOnDelete();
            $table->foreignUuid('medication_id')->nullable()
                ->constrained('medications')->nullOnDelete();
            $table->string('medication_name')->nullable(); // snapshot nama

            $table->string('dose')->nullable();
            $table->string('route')->nullable();

            $table->timestamp('scheduled_at')->nullable();   // untuk penjadwalan (opsional, fase lanjut)
            $table->timestamp('administered_at')->nullable(); // jam pemberian nyata

            $table->foreignUuid('administered_by_id')->nullable()
                ->constrained('employees')->nullOnDelete();

            $table->string('status')->default('GIVEN'); // GIVEN | HELD | SKIPPED
            $table->string('reason')->nullable();       // alasan HELD/SKIPPED
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['visit_id', 'administered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medication_administrations');
    }
};
