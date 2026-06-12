<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Item OVERRIDE per VARIAN tarif paket bedah. Kasus nyata: 1 paket "Phacoemulsifikasi"
 * dengan varian tarif per penjamin yang berbeda IOL-nya (Monofocal / OSAKA / ALASKA,
 * harga jual beda). Varian yang punya baris override → saat snapshot
 * (DokterService::syncVisitPackageSnapshot) item KOMPOSISI ber-tipe sama DIGANTI
 * baris override ini; tipe lain tetap ikut komposisi paket. Scope saat ini: IOL saja
 * (kolom item_type disiapkan untuk perluasan, divalidasi 'IOL' di controller).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surgery_package_tariff_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('surgery_package_tariff_id');
            $table->string('item_type', 20)->default('IOL');
            $table->uuid('item_id');
            $table->integer('quantity')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('surgery_package_tariff_id', 'spt_items_tariff_fk')
                ->references('id')->on('surgery_package_tariffs')->cascadeOnDelete();
            $table->index('surgery_package_tariff_id', 'spt_items_tariff_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surgery_package_tariff_items');
    }
};
