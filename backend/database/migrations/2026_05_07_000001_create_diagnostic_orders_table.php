<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diagnostic_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->constrained('visits')->cascadeOnDelete();
            $table->foreignUuid('ordered_by_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('test_type', 100); // OCT / USG / Biometri / Topografi
            $table->string('eye_side', 10)->nullable(); // OD / OS / OU
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('REQUESTED'); // REQUESTED / IN_PROGRESS / COMPLETED / CANCELLED
            $table->timestamps();
            $table->softDeletes();

            $table->index('visit_id');
            $table->index('status');
            $table->index('test_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diagnostic_orders');
    }
};
