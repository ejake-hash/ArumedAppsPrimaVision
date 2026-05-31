<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pharmacy_sales', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Penjualan obat bebas lepas (POS apotek) — TIDAK terikat ke Visit/RME.
            $table->string('sale_number', 40)->unique(); // INV-APT/{code}/{Y}/{m}/{seq}
            $table->string('buyer_name', 120)->nullable();
            $table->string('buyer_phone', 30)->nullable();

            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount', 14, 2)->default(0);          // diskon global (Rp)
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);

            $table->string('payment_method', 20)->default('CASH');   // CASH | CARD | TRANSFER
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->decimal('change_amount', 14, 2)->default(0);

            $table->string('status', 20)->default('PAID');           // PAID | CANCELLED
            $table->foreignUuid('sold_by_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->foreignUuid('cancelled_by_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacy_sales');
    }
};
