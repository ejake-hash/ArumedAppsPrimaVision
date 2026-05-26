<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('request_number', 30)->unique();
            $table->enum('requesting_station', [
                'ADMISI', 'TRIASE', 'REFRAKSIONIS', 'DOKTER',
                'PENUNJANG', 'BEDAH', 'KASIR', 'FARMASI',
            ]);
            $table->date('request_date');
            $table->enum('status', ['DRAFT', 'SUBMITTED', 'APPROVED', 'DELIVERED', 'CLOSED', 'REJECTED'])
                  ->default('DRAFT');
            $table->text('notes')->nullable();
            $table->uuid('requested_by')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->uuid('delivered_by')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('requesting_station');
            $table->index('status');
            $table->index('request_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_requests');
    }
};
