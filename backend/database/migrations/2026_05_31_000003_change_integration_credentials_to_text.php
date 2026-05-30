<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cast 'encrypted:array' menghasilkan ciphertext (string base64), bukan JSON.
     * Kolom jsonb menolaknya (SQLSTATE 22P02). Ubah ke text agar enkripsi at-rest
     * bekerja. Laravel meng-serialize JSON sendiri sebelum enkripsi.
     */
    public function up(): void
    {
        // Kosongkan nilai lama (jsonb) supaya konversi tipe tidak gagal & tidak
        // ada credential plaintext tersisa. Admin isi ulang via UI (terenkripsi).
        DB::table('integration_configs')->update(['credentials' => null]);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE integration_configs ALTER COLUMN credentials TYPE text USING credentials::text');
        } else {
            Schema::table('integration_configs', function ($table) {
                $table->text('credentials')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        DB::table('integration_configs')->update(['credentials' => null]);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE integration_configs ALTER COLUMN credentials TYPE jsonb USING credentials::jsonb');
        } else {
            Schema::table('integration_configs', function ($table) {
                $table->json('credentials')->nullable()->change();
            });
        }
    }
};
