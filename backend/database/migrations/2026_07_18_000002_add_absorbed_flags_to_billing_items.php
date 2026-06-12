<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marker DERIVED pada baris invoice (di-set ulang builder KasirService setiap
 * rebuild/konsolidasi — bukan kolom yang diedit user):
 *
 * - billing_items.is_absorbable: baris BOLEH di-toggle "terserap paket" oleh
 *   Kasir (obat/BHP di luar komposisi paket, visit punya paket BEDAH bertarif,
 *   bukan BPJS full-cover).
 * - billing_items.is_absorbed: baris sedang berstatus terserap (badge
 *   "Termasuk Paket" di KasirView; sumber kebenaran flag tetap di baris sumber
 *   prescription_items / surgery_request_bhp).
 *
 * Sengaja BUKAN di notes (notes baris OBAT sudah dipakai aturan pakai/dosis).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_items', function (Blueprint $table) {
            $table->boolean('is_absorbable')->default(false)->after('net_price');
            $table->boolean('is_absorbed')->default(false)->after('is_absorbable');
        });
    }

    public function down(): void
    {
        Schema::table('billing_items', function (Blueprint $table) {
            $table->dropColumn(['is_absorbable', 'is_absorbed']);
        });
    }
};
