<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('icd10_codes', function (Blueprint $table) {
            $table->string('chapter', 10)->nullable()->after('code');             // ex: "VII"
            $table->string('chapter_label', 255)->nullable()->after('chapter');   // ex: "Diseases of the eye and adnexa"
            $table->string('category', 10)->nullable()->after('chapter_label');   // ex: "H25" (parent of H25.0, H25.1, ...)
            $table->string('indonesian_description', 500)->nullable()->after('description');

            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::table('icd10_codes', function (Blueprint $table) {
            $table->dropIndex(['category']);
            $table->dropColumn(['chapter', 'chapter_label', 'category', 'indonesian_description']);
        });
    }
};
