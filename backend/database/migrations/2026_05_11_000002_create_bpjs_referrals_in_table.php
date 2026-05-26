<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bpjs_referrals_in', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->nullable()->constrained('visits')->nullOnDelete();
            $table->string('no_rujukan', 50)->unique();
            $table->date('tgl_rujukan')->nullable();
            $table->date('tgl_expired')->nullable();
            $table->string('fktp_kode', 20)->nullable();
            $table->string('fktp_nama', 255)->nullable();
            $table->string('diagnosa_rujukan', 10)->nullable(); // ICD-10
            $table->string('diagnosa_nama', 500)->nullable();
            $table->integer('max_kunjungan')->nullable();
            $table->integer('sisa_kunjungan')->nullable();
            $table->integer('kunjungan_ke')->nullable();
            $table->boolean('is_notified_expired')->default(false);
            $table->string('status', 20)->default('VALID'); // VALID / EXPIRED / USED_UP / INVALID
            $table->jsonb('vclaim_response')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('visit_id');
            $table->index('status');
            $table->index('tgl_expired');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bpjs_referrals_in');
    }
};
