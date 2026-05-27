<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pasien tidak selalu punya NIK (WNA pakai paspor, anak pakai KIA, pasien
 * darurat tanpa identitas, dll). Tambah kolom `identity_type` agar loket bisa
 * memilih jenis identitas, dan longgarkan `nik` jadi nullable + lebih lebar
 * supaya muat nomor paspor/SIM/KIA. Validasi size:16 hanya untuk tipe KTP
 * ditegakkan di controller, bukan di kolom.
 *
 * Tambah juga `photo_path` — foto pasien diambil saat registrasi (webcam/HP),
 * file disimpan di disk `public` (storage/app/public/patients), DB cukup
 * menyimpan path-nya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('identity_type', 20)->default('KTP')->after('no_rm');
            $table->string('photo_path')->nullable()->after('allergy_notes');
            // NIK kini opsional (Tanpa Identitas) & cukup lebar untuk paspor/SIM/KIA.
            $table->string('nik', 50)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn(['identity_type', 'photo_path']);
            $table->string('nik', 16)->nullable()->change();
        });
    }
};
