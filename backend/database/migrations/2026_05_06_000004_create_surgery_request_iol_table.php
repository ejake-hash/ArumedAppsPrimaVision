<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surgery_request_iol', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('surgery_request_id')->constrained('surgery_requests')->cascadeOnDelete();
            $table->string('eye_side', 5); // OD / OS
            $table->string('requested_iol_type', 20)->nullable(); // MONOFOCAL / MULTIFOCAL / TORIC / TRIFOCAL / EDOF / PHAKIC
            $table->decimal('requested_power', 5, 2)->nullable();
            // Assigned by farmasi after preparation
            $table->foreignUuid('iol_item_id')->nullable()->constrained('iol_items')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('surgery_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surgery_request_iol');
    }
};
