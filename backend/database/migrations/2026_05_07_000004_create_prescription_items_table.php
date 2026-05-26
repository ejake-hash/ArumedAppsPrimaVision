<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prescription_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('prescription_id')->constrained('prescriptions')->cascadeOnDelete();
            $table->foreignUuid('medication_id')->constrained('medications')->restrictOnDelete();
            $table->integer('quantity')->default(1);
            $table->string('dosage', 100)->nullable();
            $table->string('instructions', 255)->nullable(); // aturan pakai
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('prescription_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prescription_items');
    }
};
