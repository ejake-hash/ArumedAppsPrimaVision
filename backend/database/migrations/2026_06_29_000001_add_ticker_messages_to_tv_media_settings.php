<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tv_media_settings', function (Blueprint $table) {
            // Running text / ticker bawah layar — array of string disimpan JSON.
            // Sebelumnya hanya state lokal di AntreanTVView (hilang saat reload &
            // tidak sinkron antar-TV); kini ikut singleton media + broadcast.
            $table->json('ticker_messages')->nullable()->after('slide_interval');
        });
    }

    public function down(): void
    {
        Schema::table('tv_media_settings', function (Blueprint $table) {
            $table->dropColumn('ticker_messages');
        });
    }
};
