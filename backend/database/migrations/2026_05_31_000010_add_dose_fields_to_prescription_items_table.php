<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah kolom aturan pakai granular ke prescription_items.
 *
 * DokterService::storePrescription menulis dose/frequency/route/duration_days
 * (dikirim & dibaca-balik oleh DokterView sbg field terpisah), tapi kolomnya
 * belum ada → Eloquent diam-diam membuang data (BUG#9). Migration ini menyimpan
 * data granular tsb agar resep dokter tersimpan utuh untuk apotek & e-resep.
 *
 * Kolom lama `dosage`/`instructions` dipertahankan (kompatibilitas) — tidak dihapus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prescription_items', function (Blueprint $table) {
            $table->string('dose', 100)->nullable()->after('quantity');        // jumlah / dosis, mis. "1 tetes", "500mg"
            $table->string('frequency', 100)->nullable()->after('dose');       // signa, mis. "3×/hari"
            $table->string('route', 100)->nullable()->after('frequency');      // rute / posisi, mis. "ODS", "oral"
            $table->integer('duration_days')->nullable()->after('route');      // durasi pemakaian (hari)
        });
    }

    public function down(): void
    {
        Schema::table('prescription_items', function (Blueprint $table) {
            $table->dropColumn(['dose', 'frequency', 'route', 'duration_days']);
        });
    }
};
