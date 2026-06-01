<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gelombang-2 migrasi Prima Vision → Arumed: kolom `legacy_uuid` untuk tabel master
 * harga/tarif/paket yang BELUM punya (medications, prescriptions, insurers, visits
 * sudah dapat di migrasi 000006). Plus `prescription_items.is_bedah` (penanda obat
 * operasi vs pulang — 17.442 item di sumber, keputusan plan #7).
 *
 * Idempotent: command Gel-2 updateOrCreate by legacy_uuid → aman re-run.
 * Lihat: Docs/migrasi data/PLAN-MIGRASI-GABUNGAN.md (bagian B).
 */
return new class extends Migration
{
    /** Tabel yang perlu legacy_uuid (string 50, nullable, indexed). */
    private array $tables = [
        'procedures',
        'procedure_tariffs',
        'surgery_packages',
        'surgery_package_tariffs',
        'bhp_items',
        'prescription_items',
    ];

    public function up(): void
    {
        foreach ($this->tables as $name) {
            if (! Schema::hasColumn($name, 'legacy_uuid')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->string('legacy_uuid', 50)->nullable()->after('id')->index();
                });
            }
        }

        // Penanda obat operasi (tercakup paket bedah, jangan dobel-tagih di rincian kasir).
        if (! Schema::hasColumn('prescription_items', 'is_bedah')) {
            Schema::table('prescription_items', function (Blueprint $table) {
                $table->boolean('is_bedah')->default(false)->after('route');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('prescription_items', 'is_bedah')) {
            Schema::table('prescription_items', function (Blueprint $table) {
                $table->dropColumn('is_bedah');
            });
        }

        foreach ($this->tables as $name) {
            if (Schema::hasColumn($name, 'legacy_uuid')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->dropIndex(['legacy_uuid']);
                    $table->dropColumn('legacy_uuid');
                });
            }
        }
    }
};
