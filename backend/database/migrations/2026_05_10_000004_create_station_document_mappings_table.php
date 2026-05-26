<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('station_document_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('station', 50); // ADMISI / TRIASE / REFRAKSIONIS / DOKTER / PENUNJANG / BEDAH / FARMASI / KASIR
            $table->foreignUuid('document_type_id')->constrained('document_types')->cascadeOnDelete();
            $table->boolean('is_available')->default(true);
            $table->boolean('can_create')->default(true);
            $table->boolean('can_submit')->default(true);
            $table->boolean('can_print')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['station', 'document_type_id']);
            $table->index('station');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('station_document_mappings');
    }
};
