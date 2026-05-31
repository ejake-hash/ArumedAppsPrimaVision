<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom legacy untuk migrasi data pasien dari Prima Vision (SIMRS lama) → Arumed.
 *
 *  - legacy_uuid : UUID pasien di Prima Vision. Jembatan FK saat migrasi (visits,
 *                  refraksi, dll di-lookup via kolom ini) + traceability/audit.
 *                  Indexed untuk lookup cepat. BUKAN primary key (id tetap di-generate
 *                  HasUuids agar tak campur ranah lama/baru).
 *  - tempat_lahir, pekerjaan          : data demografi yang belum ada di patients.
 *  - nama_kab_kota/kecamatan/kelurahan: alamat legacy disimpan sebagai STRING nama
 *                  (Prima Vision tak punya kode wilayah Kemendagri). Data pasien BARU
 *                  tetap pakai kode wilayah; dua dunia tak konflik.
 *
 * Golongan darah TIDAK ditambah (pakai `blood_type` existing). Identitas non-KTP
 * TIDAK pakai kolom terpisah (pakai `identity_type` + `nik` varchar(50) existing).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('legacy_uuid', 50)->nullable()->after('id')->index();
            $table->string('tempat_lahir', 100)->nullable()->after('date_of_birth');
            $table->string('pekerjaan', 50)->nullable()->after('tempat_lahir');
            $table->string('nama_kab_kota', 100)->nullable()->after('province');
            $table->string('nama_kecamatan', 100)->nullable()->after('nama_kab_kota');
            $table->string('nama_kelurahan', 100)->nullable()->after('nama_kecamatan');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropIndex(['legacy_uuid']);
            $table->dropColumn([
                'legacy_uuid',
                'tempat_lahir',
                'pekerjaan',
                'nama_kab_kota',
                'nama_kecamatan',
                'nama_kelurahan',
            ]);
        });
    }
};
