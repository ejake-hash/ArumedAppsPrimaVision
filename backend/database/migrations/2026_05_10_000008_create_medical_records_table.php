<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->constrained('visits')->cascadeOnDelete();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignUuid('document_type_id')->nullable()->constrained('document_types')->nullOnDelete();
            $table->foreignUuid('recorded_by_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->jsonb('data')->nullable();
            $table->text('notes')->nullable();
            $table->integer('version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index('visit_id');
            $table->index('patient_id');
            $table->index('document_type_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_records');
    }
};
