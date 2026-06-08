<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refraksionis sebagai PPA (CPPT/SOAP rawat jalan terintegrasi). Tambah S/A/P
 * pada refraction_records agar refraksionis punya entri SOAP ber-tanda-tangan
 * sendiri di timeline CPPT (compliance STARKES — tiap PPA wajib entri sendiri).
 *
 * O (Objektif) TIDAK disimpan kolom terpisah — di-derive otomatis dari data
 * refraksi yang sudah ada (visus/IOP/Rx) saat agregasi timeline.
 * Tanda tangan reuse kolom `digital_signature` + `signature_timestamp` yang
 * sudah ada di tabel. Additive nullable, prod-safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refraction_records', function (Blueprint $table) {
            $table->text('soap_s')->nullable()->after('clinical_notes'); // keluhan visus
            $table->text('soap_a')->nullable()->after('soap_s');         // kesimpulan refraksi
            $table->text('soap_p')->nullable()->after('soap_a');         // rencana: resep / rujuk dokter
        });
    }

    public function down(): void
    {
        Schema::table('refraction_records', function (Blueprint $table) {
            $table->dropColumn(['soap_s', 'soap_a', 'soap_p']);
        });
    }
};
