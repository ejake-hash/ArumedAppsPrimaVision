<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RM 3.7 Pengkajian Gawat Darurat — Fase 2.
 * Asesmen medis gawat darurat terstruktur (1:1 visit), pola doctor_examinations.
 * Blok naratif/terstruktur disimpan JSONB agar fleksibel tanpa migrasi tiap
 * tambah field. Skalar yang sering di-query/sinkron (diagnosa, kondisi pulang,
 * status finalisasi) dipisah jadi kolom.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('igd_assessments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->unique()->constrained('visits')->cascadeOnDelete();
            $table->foreignUuid('doctor_id')->nullable()->constrained('employees')->nullOnDelete();

            // SUBJECTIVE + jenis anamnese.
            // { type:AUTO|ALLO, allo_source:[], keluhan_utama, rpd, alergi, anamnesa_narasi, rpo }
            $table->jsonb('anamnesa')->nullable();

            // Riwayat psikologis/sosial/spiritual/ekonomi + kecenderungan bunuh diri.
            $table->jsonb('psikososial')->nullable();

            // Gangguan perilaku → pemicu pengkajian restrain. { status, bahaya }
            $table->jsonb('perilaku')->nullable();

            // OBJECTIVE pemeriksaan fisik per-region (Normal default + catatan).
            $table->jsonb('fisik')->nullable();

            // Pemeriksaan mata OD/OS (grid 12 baris). { visus:{od,os}, ... }
            $table->jsonb('mata_od_os')->nullable();

            // Pemeriksaan penunjang. { ekg, radiologi, lab }
            $table->jsonb('penunjang')->nullable();

            // ASSESSMENT.
            $table->string('diagnosa_kerja', 255)->nullable();        // ICD-10 code / teks
            $table->string('diagnosa_kerja_name', 255)->nullable();
            $table->text('diagnosa_banding')->nullable();

            // PLANNING. { therapi, anjuran, pengobatan, dpjp, instruksi_keluarga }
            $table->jsonb('planning')->nullable();

            // Kondisi saat pulang/pindah/rujuk + perawatan lanjutan.
            $table->string('keadaan_pulang', 20)->nullable();         // BAIK|SEDANG|BURUK|PERDARAHAN|KOMA|MENINGGAL
            $table->string('perawatan_lanjutan', 20)->nullable();     // RAWAT_JALAN|RAWAT_INAP|RAWAT_INTENSIF|DIRUJUK
            $table->timestamp('waktu_keluar')->nullable();

            // Finalisasi + tautan dokumen RM 3.7 ber-TTD (Fase 3).
            $table->boolean('is_finalized')->default(false);
            $table->timestamp('finalized_at')->nullable();
            $table->uuid('patient_document_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('visit_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('igd_assessments');
    }
};
