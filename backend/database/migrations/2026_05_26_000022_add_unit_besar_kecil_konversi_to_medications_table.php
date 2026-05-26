<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medications', function (Blueprint $table) {
            $table->string('unit_besar', 50)->nullable()->after('golongan');
            $table->string('unit_kecil', 50)->nullable()->after('unit_besar');
            $table->integer('konversi')->nullable()->after('unit_kecil');
        });
    }

    public function down(): void
    {
        Schema::table('medications', function (Blueprint $table) {
            $table->dropColumn(['unit_besar', 'unit_kecil', 'konversi']);
        });
    }
};
