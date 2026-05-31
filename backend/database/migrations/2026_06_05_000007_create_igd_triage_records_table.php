<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catatan triase IGD (vital signs pola nurse_assessments + GCS + disposisi).
     * Tabel disiapkan SEKARANG untuk menghindari migrasi ulang; belum dipakai
     * sampai modul IGD diaktifkan (fase akhir).
     */
    public function up(): void
    {
        Schema::create('igd_triage_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->constrained('visits')->cascadeOnDelete();
            $table->string('triage_level', 5)->nullable();  // ESI 1-5
            $table->string('triage_color', 10)->nullable(); // MERAH | KUNING | HIJAU | HITAM
            $table->text('chief_complaint')->nullable();

            // Vital signs (pola nurse_assessments).
            $table->integer('td_sistol')->nullable();
            $table->integer('td_diastol')->nullable();
            $table->integer('nadi')->nullable();
            $table->decimal('suhu', 4, 1)->nullable();
            $table->integer('respirasi')->nullable();
            $table->decimal('spo2', 5, 2)->nullable();

            // Glasgow Coma Scale.
            $table->integer('gcs_e')->nullable();
            $table->integer('gcs_v')->nullable();
            $table->integer('gcs_m')->nullable();

            $table->foreignUuid('triaged_by_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('triaged_at')->nullable();
            $table->string('disposition', 20)->nullable(); // PULANG | RANAP | RUJUK | MENINGGAL
            $table->timestamps();
            $table->softDeletes();

            $table->index('visit_id');
            $table->index('triage_level');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('igd_triage_records');
    }
};
