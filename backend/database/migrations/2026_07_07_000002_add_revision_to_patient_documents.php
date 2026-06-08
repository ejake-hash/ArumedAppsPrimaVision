<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Revisi dokumen final: koreksi = buat dokumen VERSI BARU (otomatis terkoreksi
 * dari data terkini) + TTD ulang; versi lama jadi SUPERSEDED (riwayat).
 *
 *  - revision               : nomor revisi (0 = asli; 1,2,… = revisi ke-N).
 *  - supersedes_document_id : dokumen lama yang digantikan versi ini.
 *
 * Additive & nullable → prod-safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_documents', function (Blueprint $table) {
            $table->unsignedInteger('revision')->default(0)->after('status');
            $table->uuid('supersedes_document_id')->nullable()->after('revision');
            $table->index('supersedes_document_id');
        });
    }

    public function down(): void
    {
        Schema::table('patient_documents', function (Blueprint $table) {
            $table->dropIndex(['supersedes_document_id']);
            $table->dropColumn(['revision', 'supersedes_document_id']);
        });
    }
};
