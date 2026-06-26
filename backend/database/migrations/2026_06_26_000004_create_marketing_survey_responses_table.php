<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Survei Kepuasan — cache tanggapan dari Google Form (lewat Sheet tanggapan,
 * anyone-with-link). Disinkron harian; row_hash menjaga idempotensi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_survey_responses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->timestamp('submitted_at')->nullable();
            $table->string('respondent_name')->nullable();
            $table->integer('score')->nullable();      // skor numerik bila terdeteksi
            $table->jsonb('payload')->nullable();      // baris mentah (header → value)
            $table->string('row_hash', 64)->unique();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_survey_responses');
    }
};
