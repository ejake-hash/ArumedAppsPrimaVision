<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('iol_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('brand');
            $table->string('model', 100)->nullable();
            $table->string('iol_type', 20); // MONOFOCAL / MULTIFOCAL / TORIC / TRIFOCAL / EDOF / PHAKIC
            $table->string('material', 50)->nullable(); // Acrylic / Silicone / PMMA
            $table->decimal('power', 5, 2)->nullable();
            $table->string('lot_number', 100)->nullable();
            $table->string('serial_number', 100)->nullable();
            $table->string('gs1_barcode', 255)->nullable();
            $table->integer('stock')->default(0);
            $table->boolean('is_used')->default(false);
            $table->decimal('price', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('iol_type');
            $table->index('is_used');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iol_items');
    }
};
