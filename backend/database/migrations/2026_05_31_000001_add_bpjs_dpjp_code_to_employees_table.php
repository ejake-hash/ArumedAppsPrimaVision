<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Kode DPJP BPJS (dari /referensi/dokter). Dipakai SEP (dpjpLayan/DPJP)
            // & sinkron jadwal dokter ke BPJS Antrean. Dipetakan via menu Jadwal Dokter.
            $table->string('bpjs_dpjp_code', 20)->nullable()->after('str');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('bpjs_dpjp_code');
        });
    }
};
