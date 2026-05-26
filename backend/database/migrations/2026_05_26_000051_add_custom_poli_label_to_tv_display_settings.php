<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tv_display_settings', function (Blueprint $table) {
            // Label override untuk variabel {poli} di template. Kalau null/kosong,
            // fallback ke stationLabel default di frontend (mis. "Bedah" → "Bedah").
            // Tidak berlaku untuk DOKTER (yang resolve poli dari jadwal dokter aktif).
            $table->string('custom_poli_label', 100)->nullable()->after('flash_badge_text');
        });
    }

    public function down(): void
    {
        Schema::table('tv_display_settings', function (Blueprint $table) {
            $table->dropColumn('custom_poli_label');
        });
    }
};
