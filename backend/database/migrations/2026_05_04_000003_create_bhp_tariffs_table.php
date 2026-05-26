<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bhp_tariffs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('bhp_item_id')->constrained('bhp_items')->cascadeOnDelete();
            $table->foreignUuid('insurer_id')->nullable()->constrained('insurers')->nullOnDelete();
            $table->string('classification', 20); // UMUM / BPJS / ASURANSI / PERUSAHAAN / SOSIAL
            $table->decimal('price', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['bhp_item_id', 'insurer_id', 'classification']);
            $table->index('classification');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bhp_tariffs');
    }
};
