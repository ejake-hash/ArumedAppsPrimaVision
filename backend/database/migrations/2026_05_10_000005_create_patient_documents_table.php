<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignUuid('visit_id')->nullable()->constrained('visits')->nullOnDelete();
            $table->foreignUuid('document_type_id')->constrained('document_types')->restrictOnDelete();
            $table->string('document_number', 100)->unique()->nullable();
            $table->string('status', 50)->default('DRAFT'); // DRAFT / WAITING_SIGNATURE / FINAL / REJECTED / VOID
            $table->string('created_by_station', 50)->nullable();
            $table->jsonb('pending_signature_roles')->nullable(); // ["DOCTOR"]
            $table->jsonb('signatures')->nullable(); // [{role, name, sign_type, signed_at, status}]
            $table->text('reject_reason')->nullable();
            $table->text('void_reason')->nullable();
            $table->integer('printed_count')->default(0);
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('patient_id');
            $table->index('visit_id');
            $table->index('status');
            $table->index('created_by_station');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_documents');
    }
};
