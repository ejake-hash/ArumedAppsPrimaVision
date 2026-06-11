<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda resep obat PASCA-BEDAH (dibuat dari BedahView, BUKAN resep dokter Tab 3).
 * Dipakai agar fitur "Buka Kembali & revisi obat pasca-bedah" mengganti resep yang
 * BENAR (pasca-bedah saja) tanpa menyentuh resep dokter pada visit yang sama.
 * Forward-only menambah kolom — tidak mengubah/menghapus data lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prescriptions', function (Blueprint $table) {
            $table->boolean('is_post_op')->default(false)->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('prescriptions', function (Blueprint $table) {
            $table->dropColumn('is_post_op');
        });
    }
};
