<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Keamanan: PIN tanda tangan digital (e-sign legal dokumen RM) sebelumnya disimpan
 * PLAINTEXT di users.pin (varchar 6). Audit 30 Jun 2026 menandai ini high — siapa pun
 * dengan akses baca tabel users bisa memalsukan tanda tangan dokter.
 *
 * Migrasi ini:
 *   1) Melebarkan kolom pin (6 → 255) agar muat hash bcrypt (60 char). WAJIB sebelum
 *      hashing, kalau tidak hash akan terpotong & semua PIN rusak.
 *   2) Meng-hash PIN plaintext yang ada DI TEMPAT (Hash::make). Dokter tetap memakai
 *      PIN yang sama persis — tanpa gangguan operasional, tanpa reset paksa.
 *
 * Idempoten: baris yang sudah ter-hash (password_get_info algo != null) dilewati.
 * Selanjutnya cast 'pin' => 'hashed' pada model User menjaga semua tulisan baru ter-hash.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Lebarkan kolom agar muat hash bcrypt (60 char).
        Schema::table('users', function (Blueprint $table) {
            $table->string('pin', 255)->nullable()->change();
        });

        // 2) Hash PIN plaintext yang sudah ada, di tempat (bypass cast via DB::table).
        DB::table('users')
            ->whereNotNull('pin')
            ->where('pin', '<>', '')
            ->orderBy('id')
            ->chunkById(200, function ($users) {
                foreach ($users as $u) {
                    $pin = (string) $u->pin;

                    // Lewati yang sudah ter-hash (idempoten bila migrasi diulang).
                    if (password_get_info($pin)['algo'] !== null) {
                        continue;
                    }

                    DB::table('users')
                        ->where('id', $u->id)
                        ->update(['pin' => Hash::make($pin)]);
                }
            });
    }

    public function down(): void
    {
        // Tidak dapat di-reverse: hash satu arah, PIN plaintext lama tidak dapat
        // dipulihkan. Kolom sengaja dibiarkan lebar (255) — aman & tak ada ruginya.
    }
};
