<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lepas CHECK constraint enum stasiun di unit_requests/unit_returns.
 *
 * Kolom `requesting_station`/`returning_station` semula `enum()` (Postgres = varchar
 * + CHECK constraint daftar 8 stasiun). Penambahan stasiun (RANAP, IGD) tak perlu
 * lagi enum-alter berulang — cukup andalkan validasi app (UnitRequest::STATIONS),
 * sama seperti kolom inventory_stocks.location yang sudah string bebas.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE unit_requests DROP CONSTRAINT IF EXISTS unit_requests_requesting_station_check');
        DB::statement('ALTER TABLE unit_returns  DROP CONSTRAINT IF EXISTS unit_returns_returning_station_check');
    }

    public function down(): void
    {
        // Best-effort: pulihkan constraint daftar LAMA (8 stasiun). Gagal bila sudah
        // ada baris berstasiun RANAP/IGD — itu disengaja agar tak menghapus data.
        $old = "'ADMISI','TRIASE','REFRAKSIONIS','DOKTER','PENUNJANG','BEDAH','KASIR','FARMASI'";
        DB::statement("ALTER TABLE unit_requests ADD CONSTRAINT unit_requests_requesting_station_check CHECK (requesting_station IN ($old))");
        DB::statement("ALTER TABLE unit_returns  ADD CONSTRAINT unit_returns_returning_station_check  CHECK (returning_station IN ($old))");
    }
};
