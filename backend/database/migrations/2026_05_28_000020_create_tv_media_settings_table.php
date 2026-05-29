<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tv_media_settings', function (Blueprint $table) {
            $table->id();
            // 'placeholder' | 'youtube' | 'localvideo' | 'slideshow'
            $table->string('media_mode', 16)->default('placeholder');
            $table->string('youtube_embed_url', 500)->nullable();
            $table->boolean('video_autoplay')->default(true);
            $table->boolean('video_loop')->default(true);
            // Path relatif di disk public (mis. tv-media/video.mp4)
            $table->string('local_video_path', 255)->nullable();
            // Slideshow: array of {url} disimpan sebagai JSON
            $table->json('slides')->nullable();
            $table->unsignedSmallInteger('slide_interval')->default(8); // detik
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tv_media_settings');
    }
};
