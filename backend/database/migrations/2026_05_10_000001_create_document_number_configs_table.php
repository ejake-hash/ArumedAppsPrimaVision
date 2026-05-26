<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_number_configs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('document_type_code', 20)->unique();
            $table->string('format', 255); // e.g. 'RME/{CODE}/{CLINIC}/{SEQ}'
            $table->string('prefix', 50)->nullable();
            $table->string('reset_period', 20)->default('NEVER'); // DAILY / MONTHLY / YEARLY / NEVER
            $table->integer('last_seq')->default(0);
            $table->integer('seq_length')->default(7);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_number_configs');
    }
};
