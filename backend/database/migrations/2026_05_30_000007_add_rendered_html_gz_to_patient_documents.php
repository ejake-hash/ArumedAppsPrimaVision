<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Form Registry — kolom `rendered_html_gz` (binary, nullable) untuk
 * gzcompress(rendered_html) snapshot. Hemat ~70-80% storage untuk dokumen
 * panjang.
 *
 * Strategi forward-only: finalize() simpan SELALU ke _gz; `rendered_html`
 * lama nullable dan dikosongkan untuk dokumen baru. snapshot() decompress
 * dari _gz dengan fallback ke `rendered_html` (untuk dokumen lama).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_documents', function (Blueprint $table) {
            $table->binary('rendered_html_gz')->nullable()->after('rendered_html');
        });
    }

    public function down(): void
    {
        Schema::table('patient_documents', function (Blueprint $table) {
            $table->dropColumn('rendered_html_gz');
        });
    }
};
