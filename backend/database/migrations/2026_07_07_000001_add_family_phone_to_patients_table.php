<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * No. telepon keluarga / wali pasien — kontak darurat alternatif di luar
     * nomor pasien sendiri (mis. pasien lansia/anak). Nullable: opsional.
     */
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('family_phone', 20)->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn('family_phone');
        });
    }
};
