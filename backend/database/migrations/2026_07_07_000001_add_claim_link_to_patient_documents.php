<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tautkan PatientDocument ke BpjsClaim untuk "Lembar Klaim" (Resume Medis versi
 * klaim — diagnosa/ICD dari koding koder, di-TTD dokter).
 *
 *  - bpjs_claim_id     : klaim sumber lembar (nullable; dokumen RM biasa = null).
 *  - claim_coding_hash : sidik koding klaim saat lembar di-generate. Dipakai
 *                        guard submit untuk deteksi koding berubah setelah TTD
 *                        (hash beda → lembar wajib di-generate & di-TTD ulang).
 *
 * Additive & nullable → prod-safe (tidak menyentuh data lama). Tanpa FK
 * constraint agar urutan drop/seed bebas; integritas dijaga di service.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_documents', function (Blueprint $table) {
            $table->uuid('bpjs_claim_id')->nullable()->after('visit_id');
            $table->string('claim_coding_hash', 64)->nullable()->after('final_integrity_hash');
            $table->index('bpjs_claim_id');
        });
    }

    public function down(): void
    {
        Schema::table('patient_documents', function (Blueprint $table) {
            $table->dropIndex(['bpjs_claim_id']);
            $table->dropColumn(['bpjs_claim_id', 'claim_coding_hash']);
        });
    }
};
