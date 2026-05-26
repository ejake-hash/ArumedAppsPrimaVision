<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('billing_invoice_id')->constrained('billing_invoices')->cascadeOnDelete();
            $table->string('item_type', 50); // REGISTRASI / TINDAKAN / OBAT / BHP / IOL
            $table->uuid('reference_id')->nullable(); // polymorphic reference
            $table->string('description', 255);
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('total_price', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('billing_invoice_id');
            $table->index('item_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_items');
    }
};
