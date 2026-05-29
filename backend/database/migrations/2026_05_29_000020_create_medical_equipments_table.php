<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_equipments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 50)->unique();
            $table->string('name', 200);
            $table->string('category', 50)->nullable(); // mis. MICROSCOPE, PHACO_MACHINE, BIOMETRY, AUTOREFRACTOR
            $table->string('brand', 100)->nullable();
            $table->string('model', 100)->nullable();
            $table->string('serial_number', 100)->nullable();
            $table->string('location', 100)->nullable(); // mis. OK-1, Poli-2
            $table->string('status', 20)->default('ACTIVE'); // ACTIVE / MAINTENANCE / RETIRED
            $table->date('calibration_due_at')->nullable();
            $table->date('purchase_date')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('category');
            $table->index('status');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_equipments');
    }
};
