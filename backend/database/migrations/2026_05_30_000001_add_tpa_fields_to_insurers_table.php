<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Asuransi/TPA Non-BPJS — kolom tambahan di master insurers.
 * Spec: Docs/ARUMED_INSURANCE_TPA_MODULE.md (Migration 1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('insurers', function (Blueprint $table) {
            $table->string('portal_url')->nullable()->after('email');
            $table->string('pic_name')->nullable()->after('portal_url');
            $table->string('pic_phone', 30)->nullable()->after('pic_name');
            $table->string('pic_email')->nullable()->after('pic_phone');
            $table->text('claim_submission_notes')->nullable()->after('pic_email');
            // SLA approval klaim dalam hari (untuk aging alert). Default 14.
            $table->integer('sla_days')->default(14)->after('claim_submission_notes');
        });
    }

    public function down(): void
    {
        Schema::table('insurers', function (Blueprint $table) {
            $table->dropColumn([
                'portal_url', 'pic_name', 'pic_phone', 'pic_email',
                'claim_submission_notes', 'sla_days',
            ]);
        });
    }
};
