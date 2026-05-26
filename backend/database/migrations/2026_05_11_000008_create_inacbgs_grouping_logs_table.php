<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inacbgs_grouping_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->nullable()->constrained('visits')->nullOnDelete();
            $table->foreignUuid('bpjs_claim_id')->nullable()->constrained('bpjs_claims')->nullOnDelete();
            $table->string('grouper_version', 20)->nullable();
            $table->jsonb('input_diagnosis')->nullable(); // {utama: "H26.0", sekunder: ["E11.9"]}
            $table->jsonb('input_tindakan')->nullable();  // [{code: "13.41", name: "Phacoemulsification"}]
            $table->string('cbg_code', 20)->nullable();
            $table->decimal('cbg_tarif', 12, 2)->nullable();
            $table->string('severity_level', 5)->nullable(); // 1 / 2 / 3
            $table->string('engine_type', 10)->nullable(); // JAR / API
            $table->string('status', 20)->default('SUCCESS'); // SUCCESS / FAILED
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('visit_id');
            $table->index('bpjs_claim_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inacbgs_grouping_logs');
    }
};
