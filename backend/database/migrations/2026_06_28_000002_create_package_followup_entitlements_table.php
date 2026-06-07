<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LEDGER hak "konsultasi kontrol gratis pasca-bedah".
 *
 * Diterbitkan SAAT OPERASI SELESAI (BedahService::completeOperation) untuk tiap paket
 * pasien yang punya followup_procedure_id (lihat surgery_packages). Satu baris = satu
 * hak per operasi. Ditebus di VISIT KONTROL berikutnya (penjamin UMUM) oleh KasirService:
 * baris diskon "Konsultasi termasuk paket (kontrol pasca-bedah)" menetralkan tagihan
 * konsultasi, lalu used_count dinaikkan saat pembayaran lunas.
 *
 * Kenapa ledger (bukan tautan visit→visit): kontrol adalah visit BARU; ledger memisahkan
 * "asal hak" dari "tempat tebus", mendukung masa berlaku, audit (siapa/kapan/dari paket
 * mana), dan mencegah dobel-tebus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_followup_entitlements', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            // Asal hak: operasi (schedule) + visit operasi + paket sumber (provenance/audit).
            $table->foreignUuid('source_visit_id')->nullable()->constrained('visits')->nullOnDelete();
            $table->foreignUuid('surgery_schedule_id')->nullable()->constrained('surgery_schedules')->nullOnDelete();
            $table->foreignUuid('source_surgery_package_id')->nullable()->constrained('surgery_packages')->nullOnDelete();

            // Prosedur konsultasi yang digratiskan (cocokkan dgn tindakan di visit kontrol).
            $table->foreignUuid('procedure_id')->constrained('procedures')->restrictOnDelete();

            $table->unsignedSmallInteger('total_count')->default(1);   // jatah (per operasi)
            $table->unsignedSmallInteger('used_count')->default(0);    // terpakai
            $table->date('valid_until')->nullable();                   // NULL = tanpa kedaluwarsa
            $table->boolean('is_active')->default(true);

            // Penebusan (kasus 1x): visit kontrol yang menebus + waktunya (audit).
            $table->foreignUuid('redeemed_visit_id')->nullable()->constrained('visits')->nullOnDelete();
            $table->timestamp('redeemed_at')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Idempoten: 1 hak per (operasi, paket sumber, prosedur). schedule_id selalu
            // diisi saat penerbitan (completeOperation) → unik bekerja, re-run tak gandakan.
            $table->unique(
                ['surgery_schedule_id', 'source_surgery_package_id', 'procedure_id'],
                'pkg_followup_unique_per_op'
            );
            $table->index(['patient_id', 'procedure_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_followup_entitlements');
    }
};
