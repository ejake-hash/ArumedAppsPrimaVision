<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * source_visit_id pada billing_items — asal kunjungan/dokter tiap baris tagihan.
 *
 * Untuk konsolidasi rujuk-internal same-day: 1 invoice (anchor) memuat baris dari
 * beberapa visit (dokter). Kolom ini menandai baris itu berasal dari visit mana →
 * Kasir mengelompokkan rincian per-dokter. NULL = baris invoice biasa (anchor /
 * tagihan lama pra-fitur) → diperlakukan sebagai milik visit invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_items', function (Blueprint $table) {
            $table->foreignUuid('source_visit_id')->nullable()->after('reference_id')
                ->constrained('visits')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('billing_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_visit_id');
        });
    }
};
