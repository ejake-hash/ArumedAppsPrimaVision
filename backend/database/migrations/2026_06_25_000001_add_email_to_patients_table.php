<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kolom email pasien — dipakai untuk mengirim kwitansi PDF ke email pasien
     * (alternatif cetak fisik). Nullable: tidak semua pasien punya/mau email.
     */
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('email', 255)->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn('email');
        });
    }
};
