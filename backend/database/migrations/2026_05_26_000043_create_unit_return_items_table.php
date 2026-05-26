<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_return_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('unit_return_id');
            $table->enum('item_type', ['MEDICATION', 'BHP', 'IOL']);
            $table->uuid('item_id');
            $table->decimal('qty_returned', 12, 2);
            $table->string('batch_no', 50)->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('condition', 30)->nullable(); // GOOD / DAMAGED / EXPIRED / NEAR_EXPIRY
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('unit_return_id')->references('id')->on('unit_returns')->cascadeOnDelete();
            $table->index(['item_type', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_return_items');
    }
};
