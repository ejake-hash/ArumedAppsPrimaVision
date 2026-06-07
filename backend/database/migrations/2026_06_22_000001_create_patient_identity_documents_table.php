<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Berkas identitas pasien (KTP — scan/foto/PDF), melekat ke PASIEN (bukan per-kunjungan).
 * Disimpan di disk PRIVAT (`local` → storage/app/private) karena KTP = PII sensitif;
 * disajikan lewat endpoint ber-auth, BUKAN URL /storage publik (beda dgn foto wajah).
 * Pola kolom meniru inpatient_documents (2026_06_05_000010) tapi FK ke patients.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_identity_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->string('doc_type', 30)->default('KTP'); // KTP (perluasan: KK | PASPOR | SIM | KIA)
            $table->string('file_path', 500);   // path relatif di disk `local`
            $table->string('file_name', 255);   // nama asli file unggahan
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable(); // bytes
            $table->uuid('uploaded_by_id')->nullable(); // employees.id (tanpa FK — konsisten inpatient_documents)
            $table->timestamps();
            $table->softDeletes();

            $table->index(['patient_id', 'doc_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_identity_documents');
    }
};
