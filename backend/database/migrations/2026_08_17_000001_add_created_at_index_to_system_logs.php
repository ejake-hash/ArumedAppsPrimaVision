<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit Log (DataPengguna → tab Audit Log) mengurutkan ORDER BY created_at DESC dan
 * memfaset DISTINCT(action) tiap muat halaman. Tanpa index created_at, tabel yang
 * tumbuh (jutaan baris di instalasi lama) memaksa full sort + scan → respons lambat.
 * Tambah index created_at + komposit (action, created_at) untuk mempercepat urut & filter.
 *
 * Catatan: pada Postgres tabel SANGAT besar, buat index dengan CREATE INDEX CONCURRENTLY
 * (di luar transaksi) agar tak mengunci tulis; di sini pakai Schema::index() portabel
 * (SQLite test + PG). Jalankan saat maintenance window bila tabel sudah masif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_logs', function (Blueprint $table) {
            $table->index('created_at', 'system_logs_created_at_index');
            $table->index(['action', 'created_at'], 'system_logs_action_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('system_logs', function (Blueprint $table) {
            $table->dropIndex('system_logs_created_at_index');
            $table->dropIndex('system_logs_action_created_at_index');
        });
    }
};
