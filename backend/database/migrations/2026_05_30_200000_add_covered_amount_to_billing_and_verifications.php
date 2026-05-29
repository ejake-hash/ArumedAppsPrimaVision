<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asuransi/TPA — porsi tanggungan asuransi pada tagihan pasien.
 *
 * - billing_invoices.covered_amount : nominal ditanggung asuransi (sisa pasien = total − covered − paid).
 * - insurance_verifications.covered_amount : nominal cover yang diinput admin saat verifikasi
 *   (sumber kebenaran; disinkron ke billing_invoices saat verifikasi disimpan).
 *
 * payment_method invoice mendapat nilai baru 'INSURANCE' untuk kasus full cover
 * (pasien tidak membayar; kasir hanya konfirmasi). Kolom tetap string(20), tak perlu migrasi enum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table) {
            $table->decimal('covered_amount', 12, 2)->default(0)->after('paid_amount');
            $table->uuid('covered_by')->nullable()->after('covered_amount'); // audit user (konvensi: tanpa FK)
            $table->timestamp('covered_at')->nullable()->after('covered_by');
        });

        Schema::table('insurance_verifications', function (Blueprint $table) {
            // NULL = belum ditentukan (kasir pakai estimasi copay). 0 = tidak ditanggung. >0 = nominal cover.
            $table->decimal('covered_amount', 15, 2)->nullable()->after('copayment_amount');
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table) {
            $table->dropColumn(['covered_amount', 'covered_by', 'covered_at']);
        });

        Schema::table('insurance_verifications', function (Blueprint $table) {
            $table->dropColumn('covered_amount');
        });
    }
};
