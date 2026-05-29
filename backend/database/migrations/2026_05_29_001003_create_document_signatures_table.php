<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Form Registry — tabel TTD digital (append-only).
 *
 * PK: uuid (sinkron dengan document_templates / patient_documents).
 * Tidak ada updated_at — record IMMUTABLE setelah dibuat.
 * Update/delete dilarang di level service (SignatureService::update() throw).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_signatures', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Identitas TTD (untuk display + verifikasi cross-system)
            $table->string('signature_id', 50)->unique();   // "sig_xxxx" — eksternal-facing id

            $table->foreignUuid('patient_document_id')
                ->constrained('patient_documents')
                ->cascadeOnDelete();

            $table->string('signer_type', 20);              // patient / guardian / witness / doctor / nurse / staff

            // Signer identity (salah satu wajib terisi, sesuai signer_type)
            $table->foreignUuid('signer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('signer_patient_id')->nullable()->constrained('patients')->nullOnDelete();
            $table->jsonb('signer_external_identity')->nullable(); // {nama, nik, hubungan} untuk saksi eksternal

            // Bukti TTD
            $table->longText('signature_svg')->nullable();
            $table->longText('signature_png_base64')->nullable();

            // Audit metadata
            $table->timestamp('captured_at', 3);                            // server-side timestamp
            $table->jsonb('captured_device_info');                          // {ip, user_agent, device_id}
            $table->foreignUuid('captured_by_facilitator_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->jsonb('biometric_metadata')->nullable();                // {stroke_count, duration_ms, ...}
            $table->jsonb('audit_log');                                     // event timeline
            $table->char('integrity_hash', 64);                             // SHA-256 tamper-evident

            $table->timestamp('created_at')->useCurrent();
            // TIDAK ADA updated_at — append-only.

            $table->index('patient_document_id');
            $table->index('signer_type');
            $table->index('captured_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_signatures');
    }
};
