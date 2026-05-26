<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->string('generic_name')->nullable();
            $table->string('formularium', 100); // FORNAS / FORMULARIUM GENERIK / BRANDED
            $table->string('unit', 50)->nullable();
            $table->integer('stock')->default(0);
            $table->integer('min_stock')->default(0);
            $table->decimal('price', 12, 2)->default(0);
            $table->date('expiry_date')->nullable();
            $table->string('batch_number', 100)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('formularium');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medications');
    }
};
