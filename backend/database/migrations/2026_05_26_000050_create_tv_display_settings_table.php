<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tv_display_settings', function (Blueprint $table) {
            $table->id();
            $table->string('station', 32)->unique();    // ADMISI/TRIASE/REFRAKSIONIS/DOKTER/PENUNJANG/BEDAH/KASIR/FARMASI
            $table->text('tts_template')->nullable();   // "Nomor antrean {nomor}, atas nama {nama}, silakan menuju {poli}."
            $table->string('flash_label_top', 100)->nullable();  // "Nomor Antrean Dipanggil"
            $table->string('flash_badge_text', 200)->nullable(); // "Silakan menuju {poli}"
            $table->boolean('show_name_in_flash')->default(true);
            $table->boolean('show_poly_in_flash')->default(true);
            $table->boolean('show_name_in_card')->default(true);
            $table->boolean('show_poly_in_card')->default(false);
            $table->boolean('read_name_in_tts')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tv_display_settings');
    }
};
