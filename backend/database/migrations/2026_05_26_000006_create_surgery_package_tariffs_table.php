<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surgery_package_tariffs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('surgery_package_id')->constrained('surgery_packages')->cascadeOnDelete();
            $table->foreignUuid('insurer_id')->nullable()->constrained('insurers')->nullOnDelete();
            $table->string('classification', 20); // UMUM / BPJS / ASURANSI / PERUSAHAAN / SOSIAL
            $table->decimal('sell_price', 14, 2)->default(0); // harga jual paket final
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['surgery_package_id', 'insurer_id', 'classification'], 'surgery_package_tariffs_uniq');
            $table->index('classification');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surgery_package_tariffs');
    }
};
