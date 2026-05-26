<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medications', function (Blueprint $table) {
            $table->string('form_sediaan', 20)->nullable()->after('formularium'); // TABLET/KAPSUL/SIRUP/TETES_MATA/SALEP_MATA/INJEKSI/LAIN
            $table->string('golongan', 20)->nullable()->after('form_sediaan');    // BEBAS/BEBAS_TERBATAS/KERAS/NARKOTIKA/PSIKOTROPIKA
            $table->string('composition', 500)->nullable()->after('generic_name');
            $table->string('manufacturer', 255)->nullable()->after('composition');

            $table->index('form_sediaan');
            $table->index('golongan');
        });
    }

    public function down(): void
    {
        Schema::table('medications', function (Blueprint $table) {
            $table->dropIndex(['form_sediaan']);
            $table->dropIndex(['golongan']);
            $table->dropColumn(['form_sediaan', 'golongan', 'composition', 'manufacturer']);
        });
    }
};
