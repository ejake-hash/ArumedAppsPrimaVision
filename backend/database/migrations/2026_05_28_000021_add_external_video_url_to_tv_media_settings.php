<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tv_media_settings', function (Blueprint $table) {
            // URL eksternal ke file video MP4 langsung (Drive/Dropbox/CDN/hosting).
            // Kalau ada nilainya, dipakai sebagai source video di mode 'localvideo'
            // (override local_video_path). Operator pilih: upload ATAU paste URL.
            $table->string('external_video_url', 500)->nullable()->after('local_video_path');
        });
    }

    public function down(): void
    {
        Schema::table('tv_media_settings', function (Blueprint $table) {
            $table->dropColumn('external_video_url');
        });
    }
};
