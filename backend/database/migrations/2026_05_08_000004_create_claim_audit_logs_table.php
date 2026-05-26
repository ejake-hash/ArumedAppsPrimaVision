<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claim_audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('bpjs_claim_id')->constrained('bpjs_claims')->cascadeOnDelete();
            $table->foreignUuid('performed_by_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('action', 100);
            $table->string('old_status', 50)->nullable();
            $table->string('new_status', 50)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('bpjs_claim_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_audit_logs');
    }
};
