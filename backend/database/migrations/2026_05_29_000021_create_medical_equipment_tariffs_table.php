<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_equipment_tariffs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('medical_equipment_id')->constrained('medical_equipments')->cascadeOnDelete();
            $table->foreignUuid('insurer_id')->nullable()->constrained('insurers')->nullOnDelete();
            $table->string('classification', 20); // UMUM / BPJS / ASURANSI / PERUSAHAAN / SOSIAL
            $table->decimal('price', 12, 2)->default(0); // flat per pemakaian
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['medical_equipment_id', 'insurer_id', 'classification'], 'med_eq_tariff_unique');
            $table->index('classification');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_equipment_tariffs');
    }
};
