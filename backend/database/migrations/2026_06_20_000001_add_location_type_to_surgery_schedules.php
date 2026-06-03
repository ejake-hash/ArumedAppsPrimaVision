<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Modul "Ruang Tindakan" (Laser YAG / Laser Retina-PRP).
 *
 * Dokter, saat planning bedah, memilih lokasi pelaksanaan: RUANG_BEDAH (operasi,
 * alur Bedah existing) ATAU RUANG_TINDAKAN (tindakan laser). Pasien RUANG_TINDAKAN
 * muncul di stasiun terpisah yang mem-filter kolom ini — tanpa menduplikasi tabel
 * jadwal/laporan/IOL/billing yang sudah teruji.
 *
 * Sekaligus melonggarkan surgery_package_id menjadi NULLABLE: tindakan laser boleh
 * dijadwalkan tanpa paket bedah (cukup procedure laser yang ditagih via visit_services).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surgery_schedules', function (Blueprint $table) {
            // Penanda lokasi pelaksanaan. Default RUANG_BEDAH → baris lama = operasi (no-regress).
            $table->string('location_type', 20)
                ->default('RUANG_BEDAH')
                ->after('surgery_package_id');
        });

        // Backfill eksplisit untuk baris existing (jaga-jaga bila default tak terterap retroaktif).
        DB::table('surgery_schedules')
            ->whereNull('location_type')
            ->update(['location_type' => 'RUANG_BEDAH']);

        Schema::table('surgery_schedules', function (Blueprint $table) {
            $table->index('location_type');
        });

        // Longgarkan FK paket → boleh null untuk tindakan laser tanpa paket.
        // dropForeign dulu agar bisa change() nullable, lalu pasang ulang constraint.
        Schema::table('surgery_schedules', function (Blueprint $table) {
            $table->dropForeign(['surgery_package_id']);
        });
        Schema::table('surgery_schedules', function (Blueprint $table) {
            $table->foreignUuid('surgery_package_id')->nullable()->change();
            $table->foreign('surgery_package_id')
                ->references('id')->on('surgery_packages')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('surgery_schedules', function (Blueprint $table) {
            $table->dropForeign(['surgery_package_id']);
        });
        Schema::table('surgery_schedules', function (Blueprint $table) {
            // Kembalikan NOT NULL hanya jika tak ada baris null (else biarkan untuk hindari error rollback).
            $hasNull = DB::table('surgery_schedules')->whereNull('surgery_package_id')->exists();
            if (! $hasNull) {
                $table->foreignUuid('surgery_package_id')->nullable(false)->change();
            }
            $table->foreign('surgery_package_id')
                ->references('id')->on('surgery_packages')
                ->restrictOnDelete();
        });

        Schema::table('surgery_schedules', function (Blueprint $table) {
            $table->dropIndex(['location_type']);
            $table->dropColumn('location_type');
        });
    }
};
