<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tv_branding_settings', function (Blueprint $table) {
            $table->id();
            // Logo disimpan sebagai data URL base64 (mis. "data:image/png;base64,...").
            // Disimpan inline supaya tidak butuh storage symlink — logo kecil (<=512 KB).
            $table->longText('logo_data')->nullable();
            // Teks bar atas (top bar)
            $table->string('clinic_name', 120)->default('Klinik Mata Arunika');
            $table->string('clinic_subtitle', 160)->default('Cilegon · Layar Antrean');
            // Teks panel placeholder (panel kiri)
            $table->string('placeholder_title', 160)->default('Klinik Mata Arunika Cilegon');
            $table->string('placeholder_tagline', 300)->default('Spesialis kesehatan mata terpadu — PMK No. 24/2022');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tv_branding_settings');
    }
};
