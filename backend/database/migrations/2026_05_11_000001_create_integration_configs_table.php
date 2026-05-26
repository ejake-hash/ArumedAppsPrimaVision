<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_configs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('system_name', 50)->unique(); // VCLAIM / ANTREAN / ICARE / LUPIS / INACBGS / SATUSEHAT
            $table->boolean('is_enabled')->default(false);
            $table->string('base_url', 500)->nullable();
            $table->jsonb('credentials')->nullable(); // encrypted at app layer
            $table->jsonb('configuration')->nullable();
            $table->string('last_test_status', 20)->nullable(); // SUCCESS / FAILED
            $table->timestamp('last_tested_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_configs');
    }
};
