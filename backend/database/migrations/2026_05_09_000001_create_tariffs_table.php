<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tariffs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tariff_type', 50); // PROCEDURE / MEDICATION / BHP / IOL / REGISTRATION
            $table->uuid('reference_id'); // FK to the specific master table
            $table->foreignUuid('insurer_id')->nullable()->constrained('insurers')->nullOnDelete();
            $table->string('classification', 20); // UMUM / BPJS / ASURANSI / PERUSAHAAN / SOSIAL
            $table->decimal('price', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tariff_type', 'reference_id', 'classification']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tariffs');
    }
};
