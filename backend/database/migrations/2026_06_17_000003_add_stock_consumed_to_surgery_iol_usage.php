<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * surgery_iol_usage.stock_consumed: penanda apakah stok inventory_stocks BERHASIL
 * dipotong saat record. Mencegah bug stok-bocor: deleteIolUsage HANYA mengembalikan
 * stok bila record dulu benar-benar memotongnya (kalau consume gagal → tak dipotong →
 * tak boleh dikembalikan). Idempoten.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('surgery_iol_usage', 'stock_consumed')) {
            Schema::table('surgery_iol_usage', function (Blueprint $table) {
                $table->boolean('stock_consumed')->default(false)->after('expiry_date');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('surgery_iol_usage', 'stock_consumed')) {
            Schema::table('surgery_iol_usage', function (Blueprint $table) {
                $table->dropColumn('stock_consumed');
            });
        }
    }
};
