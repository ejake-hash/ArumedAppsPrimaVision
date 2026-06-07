<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nama & harga paket per-penjamin: tarif paket kini bisa punya NAMA TAMPIL khusus
 * (mis. "Promo Operasi Katarak" untuk pasien UMUM, beda dari nama paket master) dan
 * harga yang diisi via % diskon (discount_percent) — sell_price tetap harga efektif
 * (sumber tunggal billing).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surgery_package_tariffs', function (Blueprint $table) {
            $table->string('display_name', 150)->nullable()->after('insurer_id');
            $table->decimal('discount_percent', 5, 2)->nullable()->after('sell_price');
        });
    }

    public function down(): void
    {
        Schema::table('surgery_package_tariffs', function (Blueprint $table) {
            $table->dropColumn(['display_name', 'discount_percent']);
        });
    }
};
