<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 Bridging Satu Sehat — kode KFA (Kamus Farmasi & Alkes) obat.
 * Dipakai MedicationRequest/MedicationDispense (system KFA Kemenkes).
 * Diletakkan di master `medications` (1 obat = 1 KFA); dispense baca via
 * $item->medication->kfa_code. UI muncul di menu Inventori Farmasi (master Obat).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medications', function (Blueprint $table) {
            $table->string('kfa_code', 32)->nullable()->after('code')->index();
        });
    }

    public function down(): void
    {
        Schema::table('medications', function (Blueprint $table) {
            $table->dropColumn('kfa_code');
        });
    }
};
