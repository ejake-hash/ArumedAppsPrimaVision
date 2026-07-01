<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uang muka / deposit rawat inap. Diterima Kasir SEBELUM discharge (saat pasien masih
 * dirawat), sebelum ada BillingInvoice (invoice baru dibuat saat discharge via
 * consolidateBilling). Baris HELD → saat discharge dikreditkan ke invoice (status APPLIED);
 * kelebihan → REFUNDED (kembalian). Additive/nullable — aman & reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visit_deposits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->constrained('visits')->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('payment_method', 20)->default('CASH');  // CASH | TRANSFER | DEBIT | QRIS
            $table->string('status', 20)->default('HELD');          // HELD | APPLIED | REFUNDED
            $table->string('receipt_number', 50)->nullable();
            $table->uuid('cashier_id')->nullable();                 // employees.id (tanpa FK — fleksibel)
            $table->uuid('applied_invoice_id')->nullable();         // invoice tempat deposit dikredit saat discharge
            $table->decimal('refunded_amount', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['visit_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_deposits');
    }
};
