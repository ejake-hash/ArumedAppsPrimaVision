<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pastikan kolom `expiry_date` pada master item (BHP/Obat/IOL) boleh NULL.
 *
 * Akar: di DB produksi `bhp_items.expiry_date` ternyata `NOT NULL` (drift —
 * di repo & lokal selalu nullable), sehingga Tambah/Edit BHP tanpa tanggal
 * kadaluarsa ditolak DB → popup "melanggar aturan data". Tanggal kadaluarsa
 * milik BATCH (diisi saat Penerimaan/GRN), bukan master item, jadi memang
 * harus opsional.
 *
 * Idempoten: `DROP NOT NULL` pada kolom yang sudah nullable = no-op di Postgres.
 * Dijaga per-kolom (Schema::hasColumn) dan hanya untuk driver pgsql.
 */
return new class extends Migration
{
    private array $targets = [
        'bhp_items'   => 'expiry_date',
        'medications' => 'expiry_date',
        'iol_items'   => 'expiry_date',
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        foreach ($this->targets as $table => $column) {
            if (Schema::hasColumn($table, $column)) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} DROP NOT NULL");
            }
        }
    }

    public function down(): void
    {
        // Sengaja no-op: mengembalikan NOT NULL bisa gagal bila ada baris NULL
        // dan bertentangan dengan desain (expiry opsional di master item).
    }
};
