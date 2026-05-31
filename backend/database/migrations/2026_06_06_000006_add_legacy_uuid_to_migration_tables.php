<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom `legacy_uuid` di tabel-tabel target migrasi Prima Vision → Arumed.
 *
 * Setiap tabel yang menerima data lama menyimpan UUID asal Prima Vision agar:
 *  - FK antar-tabel di-resolve via legacy_uuid (mis. visits.patient_id =
 *    lookup patients WHERE legacy_uuid = registrasi.pasien_uuid),
 *  - migrasi idempotent (firstOrCreate by legacy_uuid → aman di-run ulang),
 *  - traceability/audit ke sumber.
 *
 * patients sudah dapat legacy_uuid di migration sebelumnya. Di sini:
 * insurers, employees, medications, visits, refraction_records,
 * doctor_examinations, prescriptions.
 */
return new class extends Migration
{
    private array $tables = [
        'insurers',
        'employees',
        'medications',
        'visits',
        'refraction_records',
        'doctor_examinations',
        'prescriptions',
    ];

    public function up(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('legacy_uuid', 50)->nullable()->after('id')->index();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropIndex(['legacy_uuid']);
                $table->dropColumn('legacy_uuid');
            });
        }
    }
};
