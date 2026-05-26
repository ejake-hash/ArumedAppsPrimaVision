<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('satusehat_resource_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('satusehat_sync_log_id')->nullable()->constrained('satusehat_sync_logs')->nullOnDelete();
            $table->foreignUuid('visit_id')->nullable()->constrained('visits')->nullOnDelete();
            $table->string('resource_type', 50); // Encounter / Condition / MedicationRequest / MedicationDispense / ImagingStudy / Observation
            $table->jsonb('fhir_payload')->nullable();
            $table->jsonb('response_payload')->nullable();
            $table->integer('http_status')->nullable();
            $table->string('status', 20)->default('SUCCESS'); // SUCCESS / FAILED / SKIPPED
            $table->text('error_message')->nullable();
            $table->timestamp('retried_at')->nullable();
            $table->timestamps();

            $table->index('satusehat_sync_log_id');
            $table->index('visit_id');
            $table->index('resource_type');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('satusehat_resource_logs');
    }
};
