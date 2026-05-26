<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surgery_request_bhp', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('surgery_request_id')->constrained('surgery_requests')->cascadeOnDelete();
            $table->foreignUuid('bhp_item_id')->constrained('bhp_items')->restrictOnDelete();
            $table->integer('quantity')->default(1);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('surgery_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surgery_request_bhp');
    }
};
