<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->string('fill_frequency', 20); // ONCE_LIFETIME / PER_VISIT / PER_EPISODE
            $table->string('generate_type', 20)->default('MANUAL'); // AUTO / MANUAL / HYBRID
            $table->string('category', 50)->nullable(); // ADMINISTRASI / KLINIS / PENUNJANG / BEDAH / FARMASI / BILLING
            // Self-referential — no DB-level FK to avoid PostgreSQL constraint resolution issues
            $table->uuid('parent_id')->nullable()->index();
            $table->jsonb('required_signatures')->nullable(); // [{role, sign_type, is_required}]
            $table->boolean('show_in_rme')->default(true);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('category');
            $table->index('fill_frequency');
            $table->index('sort_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_types');
    }
};
