<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surgery_schedule_audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('surgery_schedule_id')
                ->constrained('surgery_schedules')
                ->cascadeOnDelete();
            $table->date('old_date');
            $table->date('new_date');
            $table->string('reason', 100)->nullable();
            $table->foreignUuid('changed_by_id')
                ->nullable()
                ->constrained('employees')
                ->nullOnDelete();
            $table->timestamp('changed_at');
            $table->timestamps();

            $table->index('surgery_schedule_id');
            $table->index('changed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surgery_schedule_audit_logs');
    }
};
