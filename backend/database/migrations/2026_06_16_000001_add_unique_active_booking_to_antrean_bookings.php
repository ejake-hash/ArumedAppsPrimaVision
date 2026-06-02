<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cegah double-booking aktif lewat Mobile JKN (B3 Ambil Antrean) di level DB.
 *
 * Guard aplikasi (lockForUpdate pada SELECT yang kosong) TIDAK mengunci baris yang
 * belum ada → dua request paralel utk NIK sama bisa sama-sama lolos lalu create.
 * Partial unique index ini jadi jaring pengaman atomik: satu reservasi aktif
 * (DIBOOK/CHECKIN) per (nik, doctor_schedule_id, tanggal_periksa).
 *
 * - Partial: hanya berlaku saat status aktif & nik tidak null & belum soft-delete,
 *   sehingga booking BATAL/SELESAI atau tanpa NIK tidak terhalang.
 * - Postgres-only (pakai WHERE pada index). Instance ini pgsql.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS antrean_bookings_active_unique
            ON antrean_bookings (nik, doctor_schedule_id, tanggal_periksa)
            WHERE nik IS NOT NULL
              AND deleted_at IS NULL
              AND status IN ('DIBOOK', 'CHECKIN')
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS antrean_bookings_active_unique');
    }
};
