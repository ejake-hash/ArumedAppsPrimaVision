<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 8C — Dokumen/hasil eksternal pasien rawat inap (mis. lab & radiologi dari
 * pihak ke-3 pre-op). Bukan order bertarif: RS yang membayar ke pihak ke-3 (di luar
 * alur sistem); ini hanya tempat menempel HASIL agar DPJP & dokter pre-op bisa baca.
 * Tagihan tindakan terkait (bila ada) tetap lewat alur Tindakan/procedures biasa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inpatient_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->constrained('visits')->cascadeOnDelete();
            $table->string('category', 30)->default('LAB'); // LAB | RADIOLOGI | EKG | LAINNYA
            $table->string('title', 200);
            $table->string('file_path', 500);   // path di disk public
            $table->string('file_name', 255);   // nama asli file
            $table->string('mime_type', 100)->nullable();
            $table->uuid('uploaded_by_id')->nullable(); // employees.id (tanpa FK — fleksibel)
            $table->timestamps();
            $table->softDeletes();

            $table->index(['visit_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inpatient_documents');
    }
};
