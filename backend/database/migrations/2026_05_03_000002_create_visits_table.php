<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignUuid('insurer_id')->nullable()->constrained('insurers')->nullOnDelete();
            $table->foreignUuid('registered_by_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('no_antreen', 20)->nullable();
            $table->string('no_sep', 50)->unique()->nullable();
            $table->date('visit_date');
            $table->string('classification', 50); // Baru / Pre-Op / Post-Op / Kontrol
            $table->string('current_station', 50)->default('ADMISI'); // ADMISI / TRIASE / REFRAKSIONIS / DOKTER / PENUNJANG / BEDAH / FARMASI / KASIR / SELESAI
            $table->string('guarantor_type', 20); // UMUM / BPJS / ASURANSI / PERUSAHAAN / SOSIAL

            // Parallel station tracking
            $table->timestamp('triase_completed_at')->nullable();
            $table->timestamp('refraksi_completed_at')->nullable();
            $table->boolean('ready_for_doctor')->default(false);

            // BPJS
            $table->string('bpjs_booking_code', 50)->nullable();
            $table->string('bpjs_antrean_number', 20)->nullable();
            // FK to bpjs_referrals_in / bpjs_control_letters created in Batch 11 — no constrained()
            $table->uuid('bpjs_referral_in_id')->nullable();
            $table->uuid('bpjs_control_letter_id')->nullable();

            // Satu Sehat
            $table->string('satusehat_encounter_id', 100)->nullable();
            $table->string('satusehat_sync_status', 20)->default('PENDING'); // PENDING / SYNCED / FAILED / SKIPPED
            $table->timestamp('satusehat_synced_at')->nullable();

            // Follow-up (Kontrol Ulang) — optional scheduling within PULANG_BEROBAT_JALAN
            $table->boolean('planning_follow_up')->default(false);
            $table->date('follow_up_date')->nullable();
            $table->text('follow_up_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('patient_id');
            $table->index('visit_date');
            $table->index('current_station');
            $table->index('guarantor_type');
            $table->index('classification');
            $table->index(['follow_up_date', 'planning_follow_up']);
            $table->index('satusehat_sync_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visits');
    }
};
