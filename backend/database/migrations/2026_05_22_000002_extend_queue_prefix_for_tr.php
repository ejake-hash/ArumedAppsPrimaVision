<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TRIASE & REFRAKSIONIS sekarang share prefix "TR" (2 karakter), tidak lagi
 * "T"/"R" terpisah. Kolom queue_prefix yang sebelumnya CHAR(1) perlu diperlebar
 * agar muat. Pakai VARCHAR(4) untuk ruang gerak (mis. "TR", "BPJS-A", dll).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queues', function (Blueprint $table) {
            $table->string('queue_prefix', 4)->change();
        });
    }

    public function down(): void
    {
        Schema::table('queues', function (Blueprint $table) {
            $table->char('queue_prefix', 1)->change();
        });
    }
};
