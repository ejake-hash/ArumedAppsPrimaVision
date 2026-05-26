<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinic_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('clinic_name');
            $table->string('clinic_code', 20);
            $table->text('address')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('logo_path', 500)->nullable();
            $table->string('signature_path', 500)->nullable();
            $table->string('stamp_path', 500)->nullable();
            $table->string('director_name', 255)->nullable();
            $table->string('director_sip', 100)->nullable();
            $table->string('rm_format', 50)->default('YYYYMMSEQ');
            $table->integer('rm_seq_length')->default(4);
            $table->integer('rm_last_seq')->default(0);
            $table->string('pdf_engine', 50)->default('puppeteer');
            $table->boolean('watermark_enabled')->default(false);
            $table->string('watermark_type', 20)->default('ORIGINAL');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_profiles');
    }
};
