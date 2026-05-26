<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('satusehat_sync_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->date('sync_date');
            $table->string('sync_type', 20)->default('AUTO'); // AUTO / MANUAL
            $table->string('status', 20)->default('RUNNING'); // RUNNING / SUCCESS / PARTIAL / FAILED
            $table->integer('total_sent')->default(0);
            $table->integer('total_failed')->default(0);
            $table->integer('retry_count')->default(0);
            $table->timestamp('next_retry_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('sync_date');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('satusehat_sync_logs');
    }
};
