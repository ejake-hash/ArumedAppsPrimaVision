<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Asuransi/TPA Non-BPJS — flag status verifikasi eligibility di visits.
 *
 * NONE     = tidak perlu verifikasi (UMUM/BPJS)
 * PENDING  = menunggu verifikasi billing (set otomatis saat admisi finalize
 *            dengan guarantor_type ASURANSI / PERUSAHAAN)
 * VERIFIED = sudah terkonfirmasi aktif & cover
 * ISSUE    = ada masalah (tidak aktif / tidak cover / plafon kurang)
 *
 * Spec: Docs/ARUMED_INSURANCE_TPA_MODULE.md (Migration 2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->enum('insurance_verification_status', [
                'NONE', 'PENDING', 'VERIFIED', 'ISSUE',
            ])->default('NONE')->after('insurer_id');

            $table->timestamp('insurance_verified_at')
                ->nullable()
                ->after('insurance_verification_status');

            $table->index('insurance_verification_status');
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropIndex(['insurance_verification_status']);
            $table->dropColumn(['insurance_verification_status', 'insurance_verified_at']);
        });
    }
};
