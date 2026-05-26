<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bpjs_icare_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->nullable()->constrained('visits')->nullOnDelete();
            $table->string('action', 50); // CHECK_CLAIM / GET_UTILISASI / MONITOR_KLAIM
            $table->jsonb('request_payload')->nullable();
            $table->jsonb('response_payload')->nullable();
            $table->integer('http_status')->nullable();
            $table->boolean('is_success')->default(false);
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('visit_id');
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bpjs_icare_logs');
    }
};
