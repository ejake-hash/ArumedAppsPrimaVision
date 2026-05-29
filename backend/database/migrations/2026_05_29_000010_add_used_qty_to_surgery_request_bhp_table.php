<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surgery_request_bhp', function (Blueprint $table) {
            // qty actual yg dipakai bedah post-op. Default null = belum di-finalize
            // (saat itu billing pakai `quantity` sebagai fallback).
            $table->integer('used_qty')->nullable()->after('quantity');
        });

        // Backfill: row existing dgn status RECEIVED → anggap used = quantity
        // (preserve behavior lama). Yang masih REQUESTED/SENT dibiarkan null —
        // billing akan skip baris itu sampai bedah finalize used_qty.
        DB::statement("
            UPDATE surgery_request_bhp srb
            SET used_qty = quantity
            FROM surgery_requests sr
            WHERE srb.surgery_request_id = sr.id
              AND sr.status = 'RECEIVED'
              AND srb.used_qty IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('surgery_request_bhp', function (Blueprint $table) {
            $table->dropColumn('used_qty');
        });
    }
};
