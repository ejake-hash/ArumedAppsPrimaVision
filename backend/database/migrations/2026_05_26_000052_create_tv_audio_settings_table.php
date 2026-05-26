<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tv_audio_settings', function (Blueprint $table) {
            $table->id();
            $table->string('sound_preset', 32)->default('chime');   // key dari soundPresets di frontend
            $table->decimal('sound_volume', 3, 2)->default(0.45);   // 0..1
            $table->boolean('audio_enabled')->default(true);
            $table->unsignedTinyInteger('flash_duration')->default(5);   // detik (3..10)
            $table->unsignedTinyInteger('call_delay')->default(7);       // detik (5..10)
            $table->string('tts_voice_name', 200)->nullable();      // empty = auto pilih id-ID pertama
            $table->decimal('tts_rate', 3, 2)->default(0.95);       // 0.70..1.30
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tv_audio_settings');
    }
};
