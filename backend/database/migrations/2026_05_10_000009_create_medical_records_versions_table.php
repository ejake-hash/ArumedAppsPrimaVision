<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_records_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('medical_record_id')->constrained('medical_records')->cascadeOnDelete();
            $table->integer('version');
            $table->jsonb('data')->nullable();
            $table->foreignUuid('changed_by_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('changed_at')->nullable();
            $table->timestamps();

            $table->index('medical_record_id');
            $table->index(['medical_record_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_records_versions');
    }
};
