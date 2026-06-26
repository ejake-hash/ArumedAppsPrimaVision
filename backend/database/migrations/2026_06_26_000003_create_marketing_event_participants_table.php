<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Peserta kegiatan marketing — snapshot baris Google Sheet per event.
 * row_hash = hash baris mentah → idempotent saat sync ulang (upsert by event+hash).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_event_participants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->string('name')->nullable();
            $table->string('phone', 40)->nullable();
            $table->jsonb('payload')->nullable(); // baris mentah (header → value)
            $table->string('row_hash', 64);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->foreign('event_id')->references('id')->on('marketing_events')->cascadeOnDelete();
            $table->unique(['event_id', 'row_hash']);
            $table->index('event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_event_participants');
    }
};
