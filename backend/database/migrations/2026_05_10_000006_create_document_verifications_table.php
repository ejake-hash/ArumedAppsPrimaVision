<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_verifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('patient_document_id')->unique()->constrained('patient_documents')->cascadeOnDelete();
            $table->string('verification_token', 255)->unique();
            $table->string('verification_url', 500)->nullable();
            $table->string('document_hash', 255)->nullable(); // SHA256
            $table->boolean('is_valid')->default(true);
            $table->integer('scan_count')->default(0);
            $table->timestamp('last_scanned_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_verifications');
    }
};
