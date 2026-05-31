<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Master Room/Kamar (struktur 2 level: Room → Bed).
     * Kelas rawat melekat di Room (semua bed dalam 1 room sekelas).
     * Dikelola dari halaman Profil Klinik (UI), backing-store tetap tabel ini
     * agar status occupancy real-time tetap akurat.
     */
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 20)->unique();        // mis. "305"
            $table->string('name', 100);                 // label, mis. "Room 305"
            $table->string('kelas_rawat', 5);            // kelas default semua bed di room ini (1/2/3/VIP)
            $table->string('type', 20)->default('KAMAR'); // KAMAR | ICU | ISOLASI | HCU
            $table->string('bpjs_kelas_code', 10)->nullable();
            $table->string('bpjs_ruang_code', 20)->nullable();
            $table->string('gender_policy', 10)->nullable(); // L | P | MIX (null = bebas)
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('kelas_rawat');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
