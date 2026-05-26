<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('grn_number', 30)->unique();
            $table->uuid('po_id')->nullable();
            $table->uuid('supplier_id');
            $table->date('receipt_date');
            $table->string('invoice_number', 50)->nullable();
            $table->text('notes')->nullable();
            $table->decimal('total_amount', 16, 2)->default(0);
            $table->uuid('received_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('po_id')->references('id')->on('purchase_orders')->nullOnDelete();
            $table->foreign('supplier_id')->references('id')->on('suppliers')->restrictOnDelete();
            $table->index('receipt_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipts');
    }
};
