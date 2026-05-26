<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surgery_package_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('surgery_package_id')->constrained('surgery_packages')->cascadeOnDelete();
            $table->string('item_type', 20); // PROCEDURE / MEDICATION / BHP / IOL
            $table->uuid('item_id'); // FK ke procedures / medications / bhp_items / iol_items
            $table->integer('quantity')->default(1);
            $table->decimal('default_price', 12, 2)->default(0); // snapshot harga master saat ditambahkan
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['surgery_package_id', 'item_type']);
            $table->index(['item_type', 'item_id']);
            $table->unique(['surgery_package_id', 'item_type', 'item_id'], 'surgery_package_items_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surgery_package_items');
    }
};
