<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_returns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('return_number', 30)->unique();
            $table->uuid('unit_request_id')->nullable();
            $table->enum('returning_station', [
                'ADMISI', 'TRIASE', 'REFRAKSIONIS', 'DOKTER',
                'PENUNJANG', 'BEDAH', 'KASIR', 'FARMASI',
            ]);
            $table->date('return_date');
            $table->enum('status', ['DRAFT', 'SUBMITTED', 'RECEIVED', 'REJECTED'])->default('DRAFT');
            $table->string('reason', 100)->nullable();
            $table->text('notes')->nullable();
            $table->uuid('returned_by')->nullable();
            $table->uuid('received_by')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('unit_request_id')->references('id')->on('unit_requests')->nullOnDelete();
            $table->index('returning_station');
            $table->index('status');
            $table->index('return_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_returns');
    }
};
