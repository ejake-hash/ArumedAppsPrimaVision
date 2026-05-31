<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Biaya berjalan rawat inap (running bill). Sumber kebenaran untuk
     * builder billing saat discharge → KASIR (where is_billed = false).
     * Room charge digenerate sekaligus saat discharge (tanpa cron).
     */
    public function up(): void
    {
        Schema::create('inpatient_charges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->constrained('visits')->cascadeOnDelete();
            $table->date('charge_date');
            $table->string('charge_type', 20); // ROOM | VISITE | TINDAKAN | OBAT | BHP | PENUNJANG | LAINNYA
            $table->string('reference_type', 100)->nullable();
            $table->uuid('reference_id')->nullable();
            $table->string('description', 255);
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('total_price', 14, 2)->default(0);
            $table->boolean('is_billed')->default(false);
            $table->foreignUuid('created_by_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();

            $table->index(['visit_id', 'charge_date']);
            $table->index(['visit_id', 'is_billed']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inpatient_charges');
    }
};
