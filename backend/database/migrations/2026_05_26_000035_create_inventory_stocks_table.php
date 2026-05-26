<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_stocks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->enum('item_type', ['MEDICATION', 'BHP', 'IOL']);
            $table->uuid('item_id');
            $table->string('batch_no', 50)->nullable();
            $table->date('expiry_date')->nullable();
            $table->decimal('qty_on_hand', 14, 2)->default(0);
            $table->timestamp('last_received_at')->nullable();
            $table->timestamps();

            $table->unique(['item_type', 'item_id', 'batch_no'], 'inventory_stocks_item_batch_unique');
            $table->index(['item_type', 'item_id']);
            $table->index('expiry_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_stocks');
    }
};
