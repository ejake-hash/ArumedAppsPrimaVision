<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('icd10_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 10)->unique();
            $table->string('description', 500);
            $table->boolean('is_eye_related')->default(false);
            $table->boolean('is_favorite')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_eye_related');
            $table->index('is_favorite');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('icd10_codes');
    }
};
