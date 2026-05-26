<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surgery_iol_usage', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('surgery_record_id')->constrained('surgery_records')->cascadeOnDelete();
            $table->foreignUuid('iol_item_id')->constrained('iol_items')->restrictOnDelete();
            $table->string('eye_side', 5); // OD / OS
            $table->string('brand')->nullable();
            $table->string('model', 100)->nullable();
            $table->decimal('power', 5, 2)->nullable();
            $table->string('lot_number', 100)->nullable();
            $table->string('serial_number', 100)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('surgery_record_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surgery_iol_usage');
    }
};
