<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_items', function (Blueprint $table) {
            $table->string('category', 100)->nullable()->after('item_type');
            $table->decimal('discount_amount', 12, 2)->default(0)->after('total_price');
            $table->decimal('discount_percent', 5, 2)->default(0)->after('discount_amount');
            $table->decimal('net_price', 12, 2)->default(0)->after('discount_percent');
        });

        Schema::table('billing_invoices', function (Blueprint $table) {
            $table->decimal('discount_percent', 5, 2)->default(0)->after('discount');
        });
    }

    public function down(): void
    {
        Schema::table('billing_items', function (Blueprint $table) {
            $table->dropColumn(['category', 'discount_amount', 'discount_percent', 'net_price']);
        });
        Schema::table('billing_invoices', function (Blueprint $table) {
            $table->dropColumn('discount_percent');
        });
    }
};
