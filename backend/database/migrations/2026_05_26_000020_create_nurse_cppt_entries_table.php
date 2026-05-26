<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CPPT (Catatan Perkembangan Pasien Terintegrasi) untuk perawat triase.
 *
 * Append-only timeline per visit. Asesmen awal tetap di nurse_assessments
 * (1:1 dengan visit). CPPT entries ini untuk observasi ulang TTV setelah
 * asesmen awal di-finalize (mis. dokter instruksi cek ulang TD/KGD sebelum
 * operasi).
 *
 * Soft-edit: PUT bisa update kolom + catat edited_at + edited_by_id. Versi
 * lama TIDAK disimpan; minimal ada jejak siapa edit kapan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nurse_cppt_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->constrained('visits')->cascadeOnDelete();

            // Optional reference ke asesmen awal — boleh null kalau (suatu hari)
            // CPPT diizinkan tanpa asesmen awal.
            $table->foreignUuid('nurse_assessment_id')->nullable()
                ->constrained('nurse_assessments')->nullOnDelete();

            // TTV — semua optional, perawat bisa cuma catat TD+notes
            $table->integer('td_sistol')->nullable();
            $table->integer('td_diastol')->nullable();
            $table->integer('nadi')->nullable();
            $table->decimal('suhu', 4, 1)->nullable();
            $table->integer('respirasi')->nullable();
            $table->decimal('spo2', 5, 2)->nullable();
            $table->decimal('kgd', 6, 2)->nullable();
            $table->integer('pain_scale')->nullable();

            // Catatan WAJIB (di service validation level)
            $table->text('notes');

            // Audit
            $table->foreignUuid('created_by_id')->nullable()
                ->constrained('employees')->nullOnDelete();
            $table->timestamp('edited_at')->nullable();
            $table->foreignUuid('edited_by_id')->nullable()
                ->constrained('employees')->nullOnDelete();

            $table->timestamps();

            $table->index('visit_id');
            $table->index(['visit_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nurse_cppt_entries');
    }
};
