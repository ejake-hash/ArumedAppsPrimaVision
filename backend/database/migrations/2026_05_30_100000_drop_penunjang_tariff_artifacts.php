<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Revert arsitektur tarif penunjang berbasis diagnostic_test_types.
 *
 * Penunjang kini = procedures kategori "Penunjang" (master tunggal di Tarif
 * Tindakan), tarif ikut procedure_tariffs. Maka:
 *   - kolom diagnostic_test_types.base_price tidak relevan (harga di procedures)
 *   - tabel diagnostic_test_type_tariffs tidak dipakai (tarif via procedure)
 *
 * Guard hasColumn/hasTable supaya aman di DB fresh maupun yang sudah ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('diagnostic_test_types', 'base_price')) {
            Schema::table('diagnostic_test_types', function (Blueprint $table) {
                $table->dropColumn('base_price');
            });
        }

        Schema::dropIfExists('diagnostic_test_type_tariffs');
    }

    public function down(): void
    {
        // Tidak di-restore otomatis — arsitektur lama sudah ditinggalkan.
        // (Bila perlu, jalankan ulang migration create lama secara manual.)
    }
};
