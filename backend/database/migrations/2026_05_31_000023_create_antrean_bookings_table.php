<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reservasi antrean dari Mobile JKN (WS Antrean sisi RS / Sisi B).
 *
 * Keputusan desain: "Ambil Antrean" (B3) TIDAK langsung membuat Visit/Queue —
 * hanya reservasi di sini. Visit & antrean fisik dibuat saat pasien benar-benar
 * datang (check-in B6 / petugas admisi). Mencegah no-show mengotori antrean
 * & kuota hari itu. Saat check-in, booking ditautkan ke visit_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('antrean_bookings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('kodebooking', 50)->unique();

            // Identitas calon pasien (dari Mobile JKN)
            $table->string('nik', 32)->nullable();
            $table->string('nomorkartu', 32)->nullable();
            $table->string('nohp', 20)->nullable();
            $table->string('norm', 32)->nullable();          // null bila pasien baru (belum ada RM)
            $table->foreignUuid('patient_id')->nullable()->constrained('patients')->nullOnDelete();

            // Tujuan layanan (lokal, hasil resolve dari kode BPJS)
            $table->string('poli_code', 10)->nullable();
            $table->foreignUuid('doctor_schedule_id')->nullable()->constrained('doctor_schedules')->nullOnDelete();
            $table->date('tanggal_periksa');
            $table->string('jam_praktek', 20)->nullable();
            $table->unsignedSmallInteger('jenis_kunjungan')->nullable(); // 1 FKTP,2 internal,3 kontrol,4 antar RS
            $table->string('nomor_referensi', 50)->nullable();

            // Nomor antrean yang diterbitkan RS
            $table->string('nomor_antrean', 20)->nullable();   // mis. "MAT-012"
            $table->unsignedInteger('angka_antrean')->nullable();

            // Status reservasi
            $table->string('status', 20)->default('DIBOOK');   // DIBOOK | CHECKIN | BATAL | SELESAI
            $table->timestamp('checkin_at')->nullable();
            $table->text('keterangan_batal')->nullable();
            $table->foreignUuid('visit_id')->nullable()->constrained('visits')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tanggal_periksa', 'poli_code', 'doctor_schedule_id']);
            $table->index('status');
            $table->index('nik');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('antrean_bookings');
    }
};
