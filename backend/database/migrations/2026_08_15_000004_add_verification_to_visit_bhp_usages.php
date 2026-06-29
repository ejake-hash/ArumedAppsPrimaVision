<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BHP dokter kini melalui VERIFIKASI Farmasi (mirror alur resep obat D→K→F):
 * Farmasi meninjau/mengoreksi BHP lalu "Verifikasi & Kunci" sebelum tagihan keluar.
 * `verified_at` = penanda terkunci → KasirService::buildBhpLines hanya menagih BHP
 * yang sudah verified, dan gate assertObatVerified memblok tagihan bila masih ada
 * BHP belum-verif. Additive & nullable → baris lama otomatis dianggap belum-verif
 * (akan muncul di worklist), tanpa backfill wajib.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visit_bhp_usages', function (Blueprint $table) {
            $table->timestamp('verified_at')->nullable()->after('consumed_batches');
            $table->uuid('verified_by_id')->nullable()->after('verified_at');   // employee Farmasi pengunci
        });
    }

    public function down(): void
    {
        Schema::table('visit_bhp_usages', function (Blueprint $table) {
            $table->dropColumn(['verified_at', 'verified_by_id']);
        });
    }
};
