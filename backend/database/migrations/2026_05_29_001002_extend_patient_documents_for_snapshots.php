<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Form Registry — extend patient_documents untuk snapshot-at-signing.
 *
 * Existing kolom (TIDAK diubah):
 *   - status (string(50), default 'DRAFT') — nilai lama: DRAFT/WAITING_SIGNATURE/FINAL/REJECTED/VOID
 *     nilai baru valid: RENDERED, PENDING_SIGNATURE, FINALIZED
 *   - finalized_at (timestamp, nullable)
 *
 * `rendered_html` adalah snapshot immutable saat finalize.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_documents', function (Blueprint $table) {
            $table->string('template_code', 50)->nullable()->after('document_type_id');
            $table->integer('template_version')->nullable()->after('template_code');
            $table->longText('rendered_html')->nullable()->after('signatures');
            $table->char('final_integrity_hash', 64)->nullable()->after('finalized_at');

            $table->index('template_code');
        });
    }

    public function down(): void
    {
        Schema::table('patient_documents', function (Blueprint $table) {
            $table->dropIndex(['template_code']);
            $table->dropColumn([
                'template_code',
                'template_version',
                'rendered_html',
                'final_integrity_hash',
            ]);
        });
    }
};
