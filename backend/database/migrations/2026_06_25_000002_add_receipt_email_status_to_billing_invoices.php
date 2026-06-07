<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Status pengiriman kwitansi ke email pasien — supaya kasir tahu apakah
     * email sudah TERKIRIM / masih ANTRE / GAGAL (bukan asal "dikirim").
     */
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table) {
            $table->string('receipt_email', 255)->nullable()->after('notes');
            $table->string('receipt_email_status', 16)->nullable()->after('receipt_email'); // QUEUED|SENT|FAILED
            $table->timestamp('receipt_email_at')->nullable()->after('receipt_email_status');
            $table->string('receipt_email_error', 255)->nullable()->after('receipt_email_at');
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table) {
            $table->dropColumn(['receipt_email', 'receipt_email_status', 'receipt_email_at', 'receipt_email_error']);
        });
    }
};
