<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registry TV per-perangkat. Memungkinkan satu TV menampilkan media (slideshow/
 * video/youtube/placeholder) yang BERBEDA dari TV lain — mis. slideshow promo
 * hanya di "TV Lobi". Setiap TV melaporkan `device_key` (token unik tersimpan di
 * localStorage perangkat) lalu operator memberinya nama & mengatur medianya.
 *
 * `media_synced = true` (default) → TV ikut media global (tv_media_settings
 * singleton). `false` → TV pakai kolom override di baris ini.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tv_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Token unik per-perangkat (dari localStorage TV). Dipakai TV untuk
            // mengambil config-nya sendiri & target broadcast.
            $table->string('device_key', 64)->unique();
            $table->string('name', 120)->default('TV Baru');

            // true = ikut media global; false = pakai override di bawah.
            $table->boolean('media_synced')->default(true);

            // --- Override media (mirror tv_media_settings) ---
            $table->string('media_mode', 16)->default('placeholder');
            $table->string('youtube_embed_url', 500)->nullable();
            $table->boolean('video_autoplay')->default(true);
            $table->boolean('video_loop')->default(true);
            $table->string('local_video_path', 255)->nullable();
            $table->string('external_video_url', 500)->nullable();
            $table->json('slides')->nullable();
            $table->unsignedSmallInteger('slide_interval')->default(8);
            $table->string('slide_scope', 16)->default('panel');
            $table->boolean('flash_over_fullscreen')->default(true);

            // Kapan TV terakhir melapor (heartbeat) — untuk indikator online di panel.
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tv_devices');
    }
};
