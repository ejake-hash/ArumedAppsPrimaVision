<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bpjs_aplicare_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->string('action', 50); // UPDATE_BED / CREATE_ROOM / DELETE_ROOM / SYNC_ALL
            $table->string('kodekelas', 10)->nullable();
            $table->string('koderuang', 20)->nullable();
            $table->jsonb('request_payload')->nullable();
            $table->jsonb('response_payload')->nullable();
            $table->integer('http_status')->nullable();
            $table->boolean('is_success')->default(false);
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('room_id');
            $table->index('action');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bpjs_aplicare_logs');
    }
};
