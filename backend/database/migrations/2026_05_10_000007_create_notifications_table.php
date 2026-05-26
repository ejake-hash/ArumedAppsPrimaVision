<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 50); // SIGNATURE_REQUEST / SIGNATURE_REJECTED / DOCUMENT_FINAL
            $table->foreignUuid('patient_document_id')->nullable()->constrained('patient_documents')->nullOnDelete();
            $table->string('title', 255)->nullable();
            $table->text('message')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->integer('resend_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('recipient_id');
            $table->index(['recipient_id', 'is_read']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
