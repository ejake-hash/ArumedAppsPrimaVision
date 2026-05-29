<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_equipment_usages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('medical_equipment_id')->constrained('medical_equipments')->restrictOnDelete();
            $table->foreignUuid('visit_id')->constrained('visits')->cascadeOnDelete();
            // Bisa surgery atau non-surgery (mis. biometri di poli)
            $table->foreignUuid('surgery_schedule_id')->nullable()->constrained('surgery_schedules')->nullOnDelete();
            $table->foreignUuid('used_by_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('used_at')->useCurrent();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('visit_id');
            $table->index('surgery_schedule_id');
            $table->index('used_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_equipment_usages');
    }
};
