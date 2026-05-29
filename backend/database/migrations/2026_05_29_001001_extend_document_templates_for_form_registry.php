<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Form Registry — extend document_templates.
 *
 * Existing kolom yang sudah ada di tabel asli (TIDAK ditambah ulang):
 *   - version (integer, default 1)
 *   - is_active (boolean, default true)
 *
 * Catatan PK: tabel asli pakai uuid. FK ke tabel ini wajib uuid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->string('code', 50)->nullable()->unique()->after('document_type_id');
            $table->string('kind', 20)->default('OUTPUT')->after('code');            // INPUT / OUTPUT / HYBRID
            $table->string('complexity_kind', 30)->default('SIMPLE_BINDING')->after('kind'); // SIMPLE_BINDING / SCORED_FORM / CUSTOM_COMPONENT
            $table->string('custom_component_name', 100)->nullable()->after('complexity_kind');
            $table->string('source_file_path', 500)->nullable()->after('custom_component_name');
            $table->longText('layout_html')->nullable()->after('footer_html');
            $table->jsonb('field_schema')->nullable()->after('layout_html');
            $table->jsonb('station_assignments')->nullable()->after('field_schema');
            $table->timestamp('code_locked_at')->nullable()->after('is_active');
            $table->timestamp('deprecated_at')->nullable()->after('code_locked_at');

            $table->index('code');
            $table->index('kind');
            $table->index('complexity_kind');
        });
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropIndex(['code']);
            $table->dropIndex(['kind']);
            $table->dropIndex(['complexity_kind']);
            $table->dropUnique(['code']);
            $table->dropColumn([
                'code',
                'kind',
                'complexity_kind',
                'custom_component_name',
                'source_file_path',
                'layout_html',
                'field_schema',
                'station_assignments',
                'code_locked_at',
                'deprecated_at',
            ]);
        });
    }
};
