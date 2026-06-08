<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Simpan No. Rujukan & No. Surat Kontrol BPJS (string) pada visit saat admisi —
 * agar nomor yang dipilih dari fitur "Tarik dari BPJS" (atau diketik manual)
 * TIDAK hilang dan bisa dibaca ulang saat penerbitan SEP. Sejalan dengan kolom
 * string `bpjs_booking_code` yang sudah ada. Additive & prod-safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->string('no_rujukan', 50)->nullable()->after('bpjs_booking_code');
            $table->string('no_surat_kontrol', 50)->nullable()->after('no_rujukan');
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropColumn(['no_rujukan', 'no_surat_kontrol']);
        });
    }
};
