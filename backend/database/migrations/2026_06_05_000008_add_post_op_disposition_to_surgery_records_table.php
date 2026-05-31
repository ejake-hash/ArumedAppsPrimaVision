<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Disposisi pasca-operasi: menentukan ke mana pasien diteruskan setelah
     * laporan operasi dikunci.
     *   PULANG     → KASIR (default, alur lama rawat jalan/PREOP_BEDAH).
     *   RAWAT_INAP → papan "Menunggu Kamar" (current_station=MENUNGGU_RANAP),
     *                petugas ranap admit bed via RanapService::admit.
     * Nullable → record lama otomatis valid (diperlakukan sebagai PULANG).
     */
    public function up(): void
    {
        Schema::table('surgery_records', function (Blueprint $table) {
            $table->string('post_op_disposition', 20)->nullable()->after('followup_date'); // PULANG | RAWAT_INAP
        });
    }

    public function down(): void
    {
        Schema::table('surgery_records', function (Blueprint $table) {
            $table->dropColumn('post_op_disposition');
        });
    }
};
