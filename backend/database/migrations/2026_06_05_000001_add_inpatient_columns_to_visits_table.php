<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kolom Rawat Inap (RANAP) + struktur IGD (data-only, alur ditunda) di `visits`.
     * Semua nullable / default → row lama otomatis valid (jenis_pelayanan = 'RAJAL').
     *
     * Catatan kelas:
     *  - kelas_rawat_hak = kelas HAK dari penjamin → BASIS TARIF kamar.
     *  - kelas_rawat     = kelas room AKTUAL tempat pasien dibaringkan (bisa beda saat
     *                      "titip kelas" jika room kelas hak penuh) → display/SatuSehat.
     */
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            // Jenis pelayanan ditentukan otomatis per pintu masuk.
            $table->string('jenis_pelayanan', 10)->default('RAJAL')->after('guarantor_type'); // RAJAL | IGD | RANAP

            // Kelas rawat: hak (basis tarif) vs room aktual (display).
            $table->string('kelas_rawat_hak', 5)->nullable()->after('jenis_pelayanan');
            $table->string('kelas_rawat', 5)->nullable()->after('kelas_rawat_hak');

            // Periode inap & pemulangan.
            $table->timestamp('admission_at')->nullable()->after('kelas_rawat');
            $table->timestamp('discharge_at')->nullable()->after('admission_at');
            $table->string('discharge_type', 20)->nullable()->after('discharge_at'); // PULANG_SEHAT | RUJUK | APS | MENINGGAL
            $table->text('discharge_summary')->nullable()->after('discharge_type');

            // IGD (struktur disiapkan; alur/UI ditunda ke fase akhir).
            $table->string('triase_level', 5)->nullable()->after('discharge_summary');
            $table->string('triase_color', 10)->nullable()->after('triase_level'); // MERAH | KUNING | HIJAU | HITAM
            $table->timestamp('igd_arrival_at')->nullable()->after('triase_color');
            $table->string('igd_disposition', 20)->nullable()->after('igd_arrival_at'); // PULANG | RANAP | RUJUK | MENINGGAL

            // Cache denormalized (pola bpjs_referral_in_id: tanpa constrained agar fleksibel).
            $table->uuid('ranap_room_id')->nullable()->after('igd_disposition');
            $table->uuid('ranap_bed_id')->nullable()->after('ranap_room_id');
            $table->uuid('dpjp_employee_id')->nullable()->after('ranap_bed_id');

            $table->index('jenis_pelayanan');
            $table->index('triase_level');
            $table->index(['jenis_pelayanan', 'current_station']);
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropIndex(['jenis_pelayanan']);
            $table->dropIndex(['triase_level']);
            $table->dropIndex(['jenis_pelayanan', 'current_station']);
            $table->dropColumn([
                'jenis_pelayanan',
                'kelas_rawat_hak',
                'kelas_rawat',
                'admission_at',
                'discharge_at',
                'discharge_type',
                'discharge_summary',
                'triase_level',
                'triase_color',
                'igd_arrival_at',
                'igd_disposition',
                'ranap_room_id',
                'ranap_bed_id',
                'dpjp_employee_id',
            ]);
        });
    }
};
