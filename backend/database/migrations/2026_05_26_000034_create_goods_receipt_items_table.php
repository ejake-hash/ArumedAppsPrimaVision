<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_receipt_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('grn_id');
            $table->uuid('po_item_id')->nullable();
            $table->enum('item_type', ['MEDICATION', 'BHP', 'IOL']);
            $table->uuid('item_id');
            $table->decimal('qty_received', 12, 2);
            $table->string('batch_no', 50)->nullable();
            $table->date('expiry_date')->nullable();
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('subtotal', 16, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('grn_id')->references('id')->on('goods_receipts')->cascadeOnDelete();
            $table->foreign('po_item_id')->references('id')->on('purchase_order_items')->nullOnDelete();
            $table->index(['item_type', 'item_id']);
            $table->index('expiry_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_items');
    }
};
