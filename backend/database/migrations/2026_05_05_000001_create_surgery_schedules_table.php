<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surgery_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('surgery_package_id')->constrained('surgery_packages')->restrictOnDelete();
            $table->foreignUuid('lead_surgeon_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignUuid('anesthesiologist_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('scheduled_date');
            $table->time('scheduled_time')->nullable();
            $table->string('operation_room', 100)->nullable();
            $table->string('status', 20)->default('SCHEDULED'); // SCHEDULED / IN_PROGRESS / DONE / CANCELLED
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('scheduled_date');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surgery_schedules');
    }
};
