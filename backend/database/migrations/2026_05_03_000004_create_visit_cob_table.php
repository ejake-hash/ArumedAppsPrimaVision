<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visit_cob', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->unique()->constrained('visits')->cascadeOnDelete();
            $table->string('penjamin1_type', 20); // UMUM / BPJS / ASURANSI / PERUSAHAAN / SOSIAL
            $table->foreignUuid('penjamin1_insurer_id')->nullable()->constrained('insurers')->nullOnDelete();
            $table->string('penjamin2_type', 20)->nullable(); // BPJS / ASURANSI / PERUSAHAAN
            $table->foreignUuid('penjamin2_insurer_id')->nullable()->constrained('insurers')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_cob');
    }
};
