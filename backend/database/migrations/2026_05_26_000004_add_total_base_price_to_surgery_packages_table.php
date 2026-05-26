<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surgery_packages', function (Blueprint $table) {
            $table->decimal('total_base_price', 14, 2)->default(0)->after('price');
            $table->string('category', 100)->nullable()->after('code');
            $table->string('keterangan', 500)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('surgery_packages', function (Blueprint $table) {
            $table->dropColumn(['total_base_price', 'category', 'keterangan']);
        });
    }
};
