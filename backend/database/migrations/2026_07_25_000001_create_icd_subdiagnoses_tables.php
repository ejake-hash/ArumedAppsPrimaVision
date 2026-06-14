<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sub-diagnosa per kode ICD: satu kode kanonik (icd10_codes/icd9_codes, tetap
 * unik per code = aman utuk BPJS) bisa punya banyak nama diagnosa klinis spesifik
 * (mis. H35.3 → WET AMD, DRY AMD, ERM, MACULA HOLE, …). Dokter memilih sub yang
 * spesifik; kode kanonik tetap mengalir ke klaim/SEP. Lihat plan ICD sub-diagnosa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('icd10_subdiagnoses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('icd10_code_id');
            $table->string('code', 10);                 // denormalisasi kode bertitik (H35.3)
            $table->string('name', 500);                // nama klinis spesifik
            $table->boolean('is_eye_related')->default(false);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('icd10_code_id')->references('id')->on('icd10_codes')->cascadeOnDelete();
            $table->index('code');
            $table->index('is_eye_related');
            $table->unique(['code', 'name'], 'icd10_subdiagnoses_code_name_unique');
        });

        Schema::create('icd9_subdiagnoses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('icd9_code_id');
            $table->string('code', 10);
            $table->string('name', 500);
            $table->boolean('is_eye_related')->default(false);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('icd9_code_id')->references('id')->on('icd9_codes')->cascadeOnDelete();
            $table->index('code');
            $table->index('is_eye_related');
            $table->unique(['code', 'name'], 'icd9_subdiagnoses_code_name_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('icd10_subdiagnoses');
        Schema::dropIfExists('icd9_subdiagnoses');
    }
};
