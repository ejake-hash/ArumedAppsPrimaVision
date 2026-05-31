<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bed/Tempat Tidur, anak dari Room. Satu Room punya banyak Bed
     * (jumlah diatur admin di Profil Klinik). Kelas TIDAK disimpan di bed —
     * selalu mengikuti kelas room. Contoh: Room 305 → bed code "A"/"B",
     * label "305.A"/"305.B".
     */
    public function up(): void
    {
        Schema::create('beds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('room_id')->constrained('rooms')->cascadeOnDelete();
            $table->string('code', 20);                   // mis. "A" / "B"
            $table->string('label', 50);                  // mis. "305.A" / "305.B"
            $table->string('status', 20)->default('AVAILABLE'); // AVAILABLE | OCCUPIED | CLEANING | MAINTENANCE | RESERVED
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['room_id', 'code']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beds');
    }
};
