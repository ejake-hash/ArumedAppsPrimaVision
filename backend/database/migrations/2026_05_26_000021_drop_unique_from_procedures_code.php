<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop unique constraint dari procedures.code.
 *
 * Setelah ini code BUKAN identifier unik per row, melainkan format
 * {PREFIX}-{NNN} yang di-generate dari kategori (mis. TND-001 untuk
 * kategori Tindakan baris ke-1). Combo (category, code) tetap unique
 * lewat application logic (auto-increment NNN per prefix).
 *
 * Migration awal `2026_05_02_000002_create_procedures_table.php` menamai
 * index unik default: `procedures_code_unique`.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('procedures', function (Blueprint $table) {
            $table->dropUnique('procedures_code_unique');
            $table->index('code');  // tetap di-index untuk lookup CSV (by code)
        });
    }

    public function down(): void
    {
        Schema::table('procedures', function (Blueprint $table) {
            $table->dropIndex(['code']);
            $table->unique('code', 'procedures_code_unique');
        });
    }
};
