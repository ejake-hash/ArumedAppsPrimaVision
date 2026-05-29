<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinic_profiles', function (Blueprint $table) {
            $table->json('operating_rooms')->nullable()->after('stamp_path');
        });

        DB::table('clinic_profiles')
            ->whereNull('operating_rooms')
            ->update(['operating_rooms' => json_encode(['OK 1', 'OK 2', 'OK 3'])]);
    }

    public function down(): void
    {
        Schema::table('clinic_profiles', function (Blueprint $table) {
            $table->dropColumn('operating_rooms');
        });
    }
};
