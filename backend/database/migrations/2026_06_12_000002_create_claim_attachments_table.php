<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lampiran berkas klaim BPJS (PDF/gambar hasil scan): resume rawat jalan, hasil
 * pemeriksaan penunjang, dll. yang belum dihasilkan aplikasi secara digital.
 *
 * Regulasi: PMK 24/2022 mengizinkan alih-media (scan) dokumen non-elektronik di
 * masa transisi RME. Dokumen sumber WAJIB ter-autentikasi (TTD basah/elektronik)
 * & utuh; file ini adalah salinan digital untuk berkas klaim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claim_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('bpjs_claim_id')->constrained('bpjs_claims')->cascadeOnDelete();
            $table->string('category', 30)->default('LAINNYA'); // RESUME | PENUNJANG | SEP | SURAT | LAINNYA
            $table->string('title', 200);
            $table->string('file_path', 500);  // path di disk public
            $table->string('file_name', 255);  // nama asli file
            $table->string('mime_type', 100)->nullable();
            $table->unsignedInteger('file_size')->nullable(); // bytes
            $table->uuid('uploaded_by_id')->nullable(); // employees.id (tanpa FK — fleksibel)
            $table->timestamps();
            $table->softDeletes();

            $table->index(['bpjs_claim_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_attachments');
    }
};
