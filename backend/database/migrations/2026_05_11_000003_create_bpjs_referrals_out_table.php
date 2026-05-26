<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bpjs_referrals_out', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->nullable()->constrained('visits')->nullOnDelete();
            $table->string('no_rujukan', 50)->unique()->nullable(); // filled after vClaim submit success
            $table->string('faskes_tujuan_kode', 20)->nullable();
            $table->string('faskes_tujuan_nama', 255)->nullable();
            $table->string('kode_spesialis', 10)->nullable();
            $table->string('urgency', 20)->nullable(); // ELEKTIF / SEGERA / EMERGENCY
            $table->string('diagnosa_rujukan', 10)->nullable(); // ICD-10
            $table->string('diagnosa_nama', 500)->nullable();
            $table->text('catatan_rujukan')->nullable();
            $table->date('tgl_expired')->nullable();
            $table->string('status', 20)->default('DRAFT'); // DRAFT / SUBMITTED / SUCCESS / FAILED
            $table->jsonb('vclaim_response')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('visit_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bpjs_referrals_out');
    }
};
