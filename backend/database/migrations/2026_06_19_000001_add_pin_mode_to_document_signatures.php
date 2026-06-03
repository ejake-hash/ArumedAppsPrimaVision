<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Form Registry — dukung TTD via PIN untuk nakes internal (dokter/perawat/staf).
 *
 * Sampai migrasi ini, document_signatures hanya menyimpan goresan (signature_svg).
 * Untuk nakes, TTD dilakukan dengan memverifikasi PIN akun (Hash::check) lalu
 * membubuhkan STEMPEL digital — bukan gambar coretan. Maka:
 *   - sign_method        : 'DRAW' (default, pasien/saksi) | 'PIN' (nakes)
 *   - signer_name_snapshot / signer_role_snapshot : identitas nakes saat TTD,
 *     dibekukan agar stempel & halaman verifikasi tetap akurat walau data
 *     pegawai berubah kemudian.
 *
 * signature_svg tetap nullable — untuk mode PIN dibiarkan null.
 * Tabel tetap append-only (tidak ada perubahan ke updated_at).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_signatures', function (Blueprint $table) {
            $table->string('sign_method', 10)->default('DRAW')->after('signer_type');
            $table->string('signer_name_snapshot')->nullable()->after('signer_external_identity');
            $table->string('signer_role_snapshot')->nullable()->after('signer_name_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('document_signatures', function (Blueprint $table) {
            $table->dropColumn(['sign_method', 'signer_name_snapshot', 'signer_role_snapshot']);
        });
    }
};
