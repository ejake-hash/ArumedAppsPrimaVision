<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bpjs_vclaim_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->nullable()->constrained('visits')->nullOnDelete();
            $table->string('action', 50); // GENERATE_SEP / CANCEL_SEP / UPDATE_SEP / SUBMIT_CLAIM / CHECK_STATUS / CHECK_RUJUKAN
            $table->jsonb('request_payload')->nullable();
            $table->jsonb('response_payload')->nullable();
            $table->integer('http_status')->nullable();
            $table->boolean('is_success')->default(false);
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('visit_id');
            $table->index('action');
            $table->index('is_success');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bpjs_vclaim_logs');
    }
};
