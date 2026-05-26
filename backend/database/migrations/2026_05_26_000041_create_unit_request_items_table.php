<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_request_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('unit_request_id');
            $table->enum('item_type', ['MEDICATION', 'BHP', 'IOL']);
            $table->uuid('item_id');
            $table->decimal('qty_requested', 12, 2);
            $table->decimal('qty_delivered', 12, 2)->default(0);
            $table->string('batch_no', 50)->nullable();
            $table->date('expiry_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('unit_request_id')->references('id')->on('unit_requests')->cascadeOnDelete();
            $table->index(['item_type', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_request_items');
    }
};
