<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Form Registry — addendum untuk koreksi dokumen post-FINALIZED.
 * Dokumen yang sudah FINALIZED tidak boleh diedit langsung; koreksi via tabel ini.
 *
 * PK: uuid (sinkron dengan tabel lain).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_addenda', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('patient_document_id')
                ->constrained('patient_documents')
                ->cascadeOnDelete();

            $table->text('alasan');
            $table->text('isi_koreksi');

            $table->foreignUuid('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamp('finalized_at')->nullable();

            $table->foreignUuid('signature_id')
                ->nullable()
                ->constrained('document_signatures')
                ->nullOnDelete();

            $table->timestamps();

            $table->index('patient_document_id');
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_addenda');
    }
};
