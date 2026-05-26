<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surgery_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('surgery_schedule_id')->unique()->constrained('surgery_schedules')->restrictOnDelete();
            $table->foreignUuid('visit_id')->constrained('visits')->cascadeOnDelete();
            $table->timestamp('time_in')->nullable();
            $table->timestamp('time_out')->nullable();
            $table->text('operation_notes')->nullable();
            $table->boolean('has_complication')->default(false);
            $table->text('complication_detail')->nullable();
            $table->text('post_op_instructions')->nullable();
            $table->date('followup_date')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('visit_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surgery_records');
    }
};
