<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Log pengiriman rekam medis ke BPJS (WS Rekam Medis — eclaim/rekammedis/insert).
    // fhir_payload menyimpan Bundle MENTAH (sebelum gzip+enkripsi) untuk audit/debug;
    // dataMR terenkripsi tidak disimpan.
    public function up(): void
    {
        Schema::create('bpjs_rm_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->nullable()->constrained('visits')->nullOnDelete();
            $table->string('no_sep', 30)->nullable();
            $table->string('action', 30)->default('INSERT'); // INSERT
            $table->jsonb('fhir_payload')->nullable();        // Bundle mentah (pra-enkripsi)
            $table->jsonb('response_payload')->nullable();     // balasan BPJS
            $table->integer('http_status')->nullable();
            $table->string('status', 20)->default('FAILED');   // SUCCESS | FAILED
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('visit_id');
            $table->index('no_sep');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bpjs_rm_logs');
    }
};
