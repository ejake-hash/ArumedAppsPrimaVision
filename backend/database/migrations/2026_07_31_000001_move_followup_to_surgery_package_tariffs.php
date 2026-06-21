<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pindahkan manfaat "Kontrol Pasca-Bedah" (Opsi B) dari MASTER paket ke TARIF JUAL
 * PER PENJAMIN (surgery_package_tariffs). Alasannya: manfaat kontrol gratis itu
 * komersial — nempel di varian harga jual per penjamin (mis. promo UMUM kasih 2×
 * kontrol; varian BPJS tidak, karena kontrol ditanggung jalur lain). Sebelumnya
 * field followup_* ada di surgery_packages → seragam untuk semua penjamin.
 *
 * Backfill: setiap paket yang punya followup_procedure_id disalin ke SEMUA baris
 * tarifnya, agar perilaku data lama tidak berubah. Lalu kolom di master dihapus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surgery_package_tariffs', function (Blueprint $table) {
            $table->foreignUuid('followup_procedure_id')->nullable()->after('discount_percent')
                ->constrained('procedures')->nullOnDelete();
            $table->unsignedSmallInteger('followup_count')->default(0)->after('followup_procedure_id');
            $table->unsignedSmallInteger('followup_valid_days')->nullable()->after('followup_count');
        });

        // Backfill: salin manfaat dari master paket ke semua baris tarif paket itu.
        DB::statement(<<<'SQL'
            UPDATE surgery_package_tariffs spt
            SET followup_procedure_id = sp.followup_procedure_id,
                followup_count        = sp.followup_count,
                followup_valid_days   = sp.followup_valid_days
            FROM surgery_packages sp
            WHERE spt.surgery_package_id = sp.id
              AND sp.followup_procedure_id IS NOT NULL
        SQL);

        Schema::table('surgery_packages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('followup_procedure_id');
            $table->dropColumn(['followup_count', 'followup_valid_days']);
        });
    }

    public function down(): void
    {
        Schema::table('surgery_packages', function (Blueprint $table) {
            $table->foreignUuid('followup_procedure_id')->nullable()->after('total_base_price')
                ->constrained('procedures')->nullOnDelete();
            $table->unsignedSmallInteger('followup_count')->default(0)->after('followup_procedure_id');
            $table->unsignedSmallInteger('followup_valid_days')->nullable()->after('followup_count');
        });

        // Backfill balik (best-effort): ambil manfaat dari salah satu baris tarif
        // (yang punya followup) per paket — pakai baris terbaru sebagai representatif.
        DB::statement(<<<'SQL'
            UPDATE surgery_packages sp
            SET followup_procedure_id = t.followup_procedure_id,
                followup_count        = t.followup_count,
                followup_valid_days   = t.followup_valid_days
            FROM (
                SELECT DISTINCT ON (surgery_package_id)
                       surgery_package_id, followup_procedure_id, followup_count, followup_valid_days
                FROM surgery_package_tariffs
                WHERE followup_procedure_id IS NOT NULL AND deleted_at IS NULL
                ORDER BY surgery_package_id, created_at DESC
            ) t
            WHERE sp.id = t.surgery_package_id
        SQL);

        Schema::table('surgery_package_tariffs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('followup_procedure_id');
            $table->dropColumn(['followup_count', 'followup_valid_days']);
        });
    }
};
