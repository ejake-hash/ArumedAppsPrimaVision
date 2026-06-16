<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tv_media_settings', function (Blueprint $table) {
            // Cakupan tampilan media (slideshow/video/youtube):
            // 'panel'      = hanya panel kiri (default, perilaku lama)
            // 'fullscreen' = menutupi seluruh layar (papan iklan/info)
            $table->string('slide_scope', 16)->default('panel')->after('slide_interval');
            // Saat fullscreen: tetap tampilkan flash panggilan (nomor + TTS) di atas
            // slideshow? true = TV tetap berfungsi sebagai papan antrean; false =
            // murni layar iklan tanpa interupsi panggilan.
            $table->boolean('flash_over_fullscreen')->default(true)->after('slide_scope');
        });
    }

    public function down(): void
    {
        Schema::table('tv_media_settings', function (Blueprint $table) {
            $table->dropColumn(['slide_scope', 'flash_over_fullscreen']);
        });
    }
};
