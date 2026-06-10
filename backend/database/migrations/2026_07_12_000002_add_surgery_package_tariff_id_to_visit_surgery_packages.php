<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catat VARIAN tarif terpilih pada snapshot paket pasien.
 *
 * Dengan diizinkannya >1 tarif per (paket, penjamin), saat dokter memilih paket di
 * planning ia juga memilih varian (display_name + harga). surgery_package_tariff_id
 * menyimpan baris tarif yang dipilih agar harga + nama tampil di kwitansi konsisten
 * dan tahan re-sync. NULL = pakai varian default (perilaku lama).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visit_surgery_packages', function (Blueprint $t) {
            $t->foreignUuid('surgery_package_tariff_id')
                ->nullable()
                ->after('source_surgery_package_id')
                ->constrained('surgery_package_tariffs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('visit_surgery_packages', function (Blueprint $t) {
            $t->dropConstrainedForeignId('surgery_package_tariff_id');
        });
    }
};
