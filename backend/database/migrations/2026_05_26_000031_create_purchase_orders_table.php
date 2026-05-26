<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('po_number', 30)->unique();
            $table->uuid('supplier_id');
            $table->date('po_date');
            $table->date('expected_date')->nullable();
            $table->enum('status', ['DRAFT', 'SENT', 'PARTIAL', 'RECEIVED', 'CANCELED'])->default('DRAFT');
            $table->text('notes')->nullable();
            $table->decimal('total_amount', 16, 2)->default(0);
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('supplier_id')->references('id')->on('suppliers')->restrictOnDelete();
            $table->index('status');
            $table->index('po_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
