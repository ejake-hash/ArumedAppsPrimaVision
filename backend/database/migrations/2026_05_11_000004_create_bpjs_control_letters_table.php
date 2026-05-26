<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bpjs_control_letters', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->nullable()->constrained('visits')->nullOnDelete();
            $table->string('no_surat_kontrol', 50)->unique()->nullable(); // filled after vClaim submit success

            // Synced from visits.follow_up_date when planning_follow_up = true
            $table->date('tanggal_rencana_kontrol')->nullable();
            // Typically +14 days from tanggal_rencana_kontrol (per aturan vClaim)
            $table->date('tgl_expired')->nullable();

            $table->string('faskes_kontrol_kode', 20)->nullable(); // default: own clinic
            $table->string('kode_spesialis', 10)->nullable();
            $table->boolean('is_notified_expired')->default(false);
            $table->string('status', 20)->default('DRAFT'); // DRAFT / SUBMITTED / SUCCESS / FAILED / EXPIRED / USED
            $table->jsonb('vclaim_response')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('visit_id');
            $table->index('status');
            $table->index('tanggal_rencana_kontrol');
            $table->index('tgl_expired');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bpjs_control_letters');
    }
};
