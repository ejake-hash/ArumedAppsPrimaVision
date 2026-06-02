<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah kolom audit Web Service E-Klaim ke inacbgs_grouping_logs.
 *
 * Tabel ini semula hanya mencatat hasil grouper lokal. Dengan integrasi WS
 * E-Klaim (new_claim / set_claim_data / grouper / claim_final / status / reedit),
 * tiap call WS dicatat di sini: method + request/response (terdekripsi) + kode.
 * Semua nullable agar baris grouper lama tetap valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inacbgs_grouping_logs', function (Blueprint $table) {
            $table->string('method', 40)->nullable()->after('grouper_version');   // new_claim, set_claim_data, grouper, claim_final, ...
            $table->jsonb('request')->nullable()->after('input_tindakan');         // payload yang dikirim (plaintext, sebelum enkripsi)
            $table->jsonb('response')->nullable()->after('request');               // payload balasan (plaintext, sesudah dekripsi)
            $table->string('response_code', 20)->nullable()->after('response');    // metadata.code dari ws.php
            $table->text('message')->nullable()->after('response_code');           // metadata.message dari ws.php

            $table->index('method');
        });
    }

    public function down(): void
    {
        Schema::table('inacbgs_grouping_logs', function (Blueprint $table) {
            $table->dropIndex(['method']);
            $table->dropColumn(['method', 'request', 'response', 'response_code', 'message']);
        });
    }
};
