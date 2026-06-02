<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * prescriptions.pharmacy_note — catatan dokter KHUSUS untuk petugas farmasi
 * (mis. substitusi merek, racikan, instruksi penyerahan). Terpisah dari `notes`
 * yang dipakai sebagai catatan untuk Kasir (penagihan/diskon), agar dua audiens
 * tidak saling tertukar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prescriptions', function (Blueprint $table) {
            $table->string('pharmacy_note', 500)->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('prescriptions', function (Blueprint $table) {
            $table->dropColumn('pharmacy_note');
        });
    }
};
