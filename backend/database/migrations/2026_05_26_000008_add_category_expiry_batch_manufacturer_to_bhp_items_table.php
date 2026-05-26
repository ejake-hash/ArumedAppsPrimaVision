<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bhp_items', function (Blueprint $table) {
            $table->string('category', 100)->nullable()->after('code');
            $table->string('manufacturer', 255)->nullable()->after('unit');
            $table->date('expiry_date')->nullable()->after('price');
            $table->string('batch_number', 100)->nullable()->after('expiry_date');

            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::table('bhp_items', function (Blueprint $table) {
            $table->dropIndex(['category']);
            $table->dropColumn(['category', 'manufacturer', 'expiry_date', 'batch_number']);
        });
    }
};
