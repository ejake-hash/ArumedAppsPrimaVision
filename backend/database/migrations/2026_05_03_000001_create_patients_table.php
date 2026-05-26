<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('no_rm', 50)->unique();
            $table->string('nik', 16)->unique();
            $table->string('name');
            $table->string('gender', 10)->nullable(); // L / P
            $table->date('date_of_birth')->nullable();
            $table->string('phone', 20)->nullable();
            $table->text('address')->nullable();
            $table->string('province', 100)->nullable();
            $table->string('bpjs_number', 30)->unique()->nullable();
            $table->string('blood_type', 5)->nullable();
            $table->text('allergy_notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('name');
            $table->index('date_of_birth');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
};
