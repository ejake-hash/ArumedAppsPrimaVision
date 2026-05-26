<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('icd9_codes', function (Blueprint $table) {
            $table->string('category', 10)->nullable()->after('code');                    // ex: "13" (Operations on lens)
            $table->string('indonesian_description', 500)->nullable()->after('description');

            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::table('icd9_codes', function (Blueprint $table) {
            $table->dropIndex(['category']);
            $table->dropColumn(['category', 'indonesian_description']);
        });
    }
};
