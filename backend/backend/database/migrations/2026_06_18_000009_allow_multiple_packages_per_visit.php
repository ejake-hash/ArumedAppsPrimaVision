<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Izinkan banyak paket per visit (mis. paket tindakan Phaco + paket anestesi TIVA
 * dalam satu sesi operasi).
 *
 * Sebelumnya `visit_surgery_packages` punya unique `visit_id` → 1 snapshot/visit.
 * Diganti unique komposit `(visit_id, source_surgery_package_id)` agar:
 *   - satu visit boleh punya N snapshot paket berbeda;
 *   - paket master yang sama tetap tak ter-snapshot dobel di satu visit.
 *
 * Pola soft-delete tetap ditangani di service (withTrashed → restore) agar tak 23505.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Drop unique lama (named di migrasi create: visit_surgery_packages_visit_unique).
        Schema::table('visit_surgery_packages', function (Blueprint $table) {
            $table->dropUnique('visit_surgery_packages_visit_unique');
        });

        // Unique komposit baru: cegah paket master yang sama dobel di satu visit.
        Schema::table('visit_surgery_packages', function (Blueprint $table) {
            $table->unique(['visit_id', 'source_surgery_package_id'], 'vsp_visit_source_unique');
        });
    }

    public function down(): void
    {
        Schema::table('visit_surgery_packages', function (Blueprint $table) {
            $table->dropUnique('vsp_visit_source_unique');
        });

        // Restore unique lama. CATATAN: hanya aman bila tiap visit ≤1 paket
        // (rollback dev). Bila ada visit dgn >1 paket, kosongkan dulu manual.
        Schema::table('visit_surgery_packages', function (Blueprint $table) {
            $table->unique('visit_id', 'visit_surgery_packages_visit_unique');
        });
    }
};
