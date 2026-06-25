<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * K2 — pengiriman klaim online + upload berkas digital ke DC BPJS.
 * Status DC dari send_claim_individual (kemkes/bpjs/cob) disimpan di klaim;
 * status upload berkas (file_upload → upload_dc_bpjs) disimpan per lampiran.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bpjs_claims', function (Blueprint $table) {
            $table->string('kemkes_dc_status', 20)->nullable()->after('bpjs_status');
            $table->string('bpjs_dc_status', 20)->nullable()->after('kemkes_dc_status');
            $table->string('cob_dc_status', 20)->nullable()->after('bpjs_dc_status');
            $table->timestamp('dc_sent_at')->nullable()->after('cob_dc_status');
        });

        Schema::table('claim_attachments', function (Blueprint $table) {
            // file_class E-Klaim (resume_medis/laboratorium/radiologi/penunjang_lain/…).
            $table->string('file_class', 32)->nullable()->after('category');
            // Status forward ke DC BPJS: 1=sukses, 0=gagal, null=belum diunggah.
            $table->boolean('dc_upload_status')->nullable()->after('file_size');
            $table->text('dc_upload_response')->nullable()->after('dc_upload_status');
            $table->timestamp('dc_uploaded_at')->nullable()->after('dc_upload_response');
        });
    }

    public function down(): void
    {
        Schema::table('bpjs_claims', function (Blueprint $table) {
            $table->dropColumn(['kemkes_dc_status', 'bpjs_dc_status', 'cob_dc_status', 'dc_sent_at']);
        });
        Schema::table('claim_attachments', function (Blueprint $table) {
            $table->dropColumn(['file_class', 'dc_upload_status', 'dc_upload_response', 'dc_uploaded_at']);
        });
    }
};
