<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pharmacy_sale_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pharmacy_sale_id')->constrained('pharmacy_sales')->cascadeOnDelete();
            $table->foreignUuid('medication_id')->constrained('medications')->restrictOnDelete();

            // Snapshot supaya histori tidak berubah saat master/harga diubah.
            $table->string('medication_name', 200);
            $table->decimal('unit_price', 14, 2)->default(0); // HJA saat transaksi

            $table->integer('quantity')->default(1);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('total_price', 14, 2)->default(0);

            // Batch yang dikonsumsi (FEFO) — dipakai untuk restock akurat saat batal.
            // [{batch_no, expiry_date, qty}]
            $table->json('consumed_batches')->nullable();

            $table->timestamps();

            $table->index('pharmacy_sale_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacy_sale_items');
    }
};
