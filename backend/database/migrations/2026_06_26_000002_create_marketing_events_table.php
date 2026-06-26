<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Program Marketing & Event — katalog kegiatan marketing. Data peserta ditarik
 * harian dari Google Sheet (participant_sheet_url, anyone-with-link) ke tabel
 * marketing_event_participants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->date('event_date')->nullable();
            $table->string('location')->nullable();
            $table->text('description')->nullable();
            $table->string('participant_sheet_url')->nullable(); // Google Sheet peserta
            $table->string('participant_gid')->nullable();        // gid tab (opsional)
            $table->timestamp('participants_synced_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('event_date');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_events');
    }
};
