<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diagnostic_test_type_tariffs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('diagnostic_test_type_id')->constrained('diagnostic_test_types')->cascadeOnDelete();
            $table->foreignUuid('insurer_id')->constrained('insurers')->cascadeOnDelete();
            $table->decimal('price', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['diagnostic_test_type_id', 'insurer_id'], 'dtt_tariff_unique');
            $table->index(['diagnostic_test_type_id', 'insurer_id']);
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diagnostic_test_type_tariffs');
    }
};
