<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Manfaat Kontrol Pasca-Bedah" pada PAKET (Opsi B — kartu terpisah, bukan item
 * komposisi). Sebuah paket bisa memberi hak KONSULTASI GRATIS saat pasien kontrol
 * pasca-operasi:
 *   - followup_procedure_id : prosedur konsultasi yang digratiskan (mis. "Konsultasi
 *                              Dokter Spesialis"). NULL = paket TIDAK memberi manfaat
 *                              (default aman; semua paket lama tetap NULL → tanpa efek).
 *   - followup_count        : jumlah gratis PER OPERASI (default 1).
 *   - followup_valid_days   : masa berlaku hak (hari) sejak operasi. NULL = tanpa
 *                              kedaluwarsa (keputusan default; bisa diisi bila direktur
 *                              menetapkan batas via surat edaran — forward-compatible).
 *
 * Manfaat ini TIDAK ditagih di visit operasi; ia menerbitkan "hak" yang ditebus di
 * visit kontrol berikutnya (lihat package_followup_entitlements + KasirService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surgery_packages', function (Blueprint $table) {
            $table->foreignUuid('followup_procedure_id')->nullable()->after('total_base_price')
                ->constrained('procedures')->nullOnDelete();
            $table->unsignedSmallInteger('followup_count')->default(0)->after('followup_procedure_id');
            $table->unsignedSmallInteger('followup_valid_days')->nullable()->after('followup_count');
        });
    }

    public function down(): void
    {
        Schema::table('surgery_packages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('followup_procedure_id');
            $table->dropColumn(['followup_count', 'followup_valid_days']);
        });
    }
};
