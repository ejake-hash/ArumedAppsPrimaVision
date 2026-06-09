<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gate verifikasi Farmasi sebelum tagihan Kasir (alur D→K→F).
 *
 * - prescriptions.verified_by_id / verified_at: penanda "resep sudah diverifikasi &
 *   dikunci Farmasi". KasirService::consolidateBilling MENOLAK membuat tagihan bila
 *   masih ada resep RAJAL belum diverifikasi (verified_at NULL).
 * - prescription_items.original_medication_id / change_reason / changed_by_id /
 *   changed_at: jejak audit bila Farmasi substitusi/ubah qty/hapus item saat verifikasi
 *   (wajib alasan terstruktur — penting utk audit klaim BPJS).
 *
 * Semua kolom additive & nullable. BACKFILL verified_at untuk SEMUA resep existing
 * (verified_at = created_at) supaya resep yang sudah berjalan/selesai TIDAK terblok
 * oleh gate baru. Gate hanya berlaku untuk resep baru (verified_at NULL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prescriptions', function (Blueprint $table) {
            $table->foreignUuid('verified_by_id')->nullable()->after('prescribed_by_id')
                ->constrained('employees')->nullOnDelete();
            $table->timestamp('verified_at')->nullable()->after('verified_by_id');
            $table->index('verified_at');
        });

        Schema::table('prescription_items', function (Blueprint $table) {
            // Obat asli dokter sebelum disubstitusi Farmasi (null bila tak disubstitusi).
            $table->foreignUuid('original_medication_id')->nullable()->after('medication_id')
                ->constrained('medications')->nullOnDelete();
            // Alasan perubahan terstruktur (mis. STOK_HABIS / OVER_BUDGET_BPJS / dll).
            $table->string('change_reason', 120)->nullable()->after('source');
            $table->foreignUuid('changed_by_id')->nullable()->after('change_reason')
                ->constrained('employees')->nullOnDelete();
            $table->timestamp('changed_at')->nullable()->after('changed_by_id');
        });

        // Backfill anti in-flight: resep lama dianggap sudah terverifikasi.
        DB::statement('UPDATE prescriptions SET verified_at = created_at WHERE verified_at IS NULL');
    }

    public function down(): void
    {
        Schema::table('prescription_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('original_medication_id');
            $table->dropConstrainedForeignId('changed_by_id');
            $table->dropColumn(['change_reason', 'changed_at']);
        });

        Schema::table('prescriptions', function (Blueprint $table) {
            $table->dropIndex(['verified_at']);
            $table->dropConstrainedForeignId('verified_by_id');
            $table->dropColumn('verified_at');
        });
    }
};
