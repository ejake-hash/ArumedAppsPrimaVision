<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nurse_assessments', function (Blueprint $table) {
            $table->integer('pain_scale')->nullable()->after('kgd');    // NRS 0–10
            $table->text('rps')->nullable()->after('chief_complaint');   // Riwayat Penyakit Sekarang
        });
    }

    public function down(): void
    {
        Schema::table('nurse_assessments', function (Blueprint $table) {
            $table->dropColumn(['pain_scale', 'rps']);
        });
    }
};
