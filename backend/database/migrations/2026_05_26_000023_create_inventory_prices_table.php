<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_prices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->enum('item_type', ['MEDICATION', 'BHP', 'IOL']);
            $table->uuid('item_id');
            $table->decimal('hpp', 14, 2)->default(0);
            $table->decimal('margin_percent', 6, 2)->default(0);
            $table->boolean('ppn_enabled')->default(true);
            $table->decimal('hja', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->date('effective_date')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['item_type', 'item_id'], 'inventory_prices_item_unique');
            $table->index('item_type');
        });

        Schema::create('inventory_price_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->decimal('ppn_rate', 5, 2)->default(11.00);
            $table->timestamps();
        });

        \DB::table('inventory_price_settings')->insert([
            'id'         => (string) \Illuminate\Support\Str::uuid(),
            'ppn_rate'   => 11.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_prices');
        Schema::dropIfExists('inventory_price_settings');
    }
};
