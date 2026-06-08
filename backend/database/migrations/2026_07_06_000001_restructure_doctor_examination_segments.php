<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restrukturisasi pemeriksaan mata dokter (DokterView Tab 2):
 *
 *  1. Segmen anterior/posterior berubah dari pilihan enum (varchar 50:
 *     "Normal" / "Tidak Normal" / "Tidak Dapat Dinilai") menjadi TEXT bebas
 *     (dokter mengetik temuan sendiri). 18 kolom segmen di-ALTER ke `text`
 *     in-place via raw ALTER (PostgreSQL, tanpa doctrine/dbal, tanpa rewrite
 *     tabel). Nilai lama "Normal" dst tetap terbaca sebagai teks.
 *  2. Tambah field anterior `sa_palpebra_od/os` (di atas Kornea pada UI) +
 *     2 catatan bebas: `sa_notes` (bawah segmen anterior) & `sp_notes` (bawah
 *     segmen posterior).
 *
 * `slitlamp_notes` DIBIARKAN (kolom tetap ada, tidak lagi dipakai UI).
 * Semua tambahan additive nullable → prod-safe. down() drop 4 kolom baru;
 * tipe text→varchar TIDAK dikembalikan (text superset; hindari truncation).
 */
return new class extends Migration
{
    /** 18 kolom segmen yang dialih-tipe varchar(50) → text. */
    private array $segmentColumns = [
        'sa_kornea_od', 'sa_coa_od', 'sa_iris_od', 'sa_pupil_od', 'sa_lensa_od',
        'sa_kornea_os', 'sa_coa_os', 'sa_iris_os', 'sa_pupil_os', 'sa_lensa_os',
        'sp_papil_od', 'sp_macula_od', 'sp_retina_od', 'sp_vitreous_od',
        'sp_papil_os', 'sp_macula_os', 'sp_retina_os', 'sp_vitreous_os',
    ];

    public function up(): void
    {
        // 1. varchar(50) → text (raw ALTER, in-place, prod-safe di PostgreSQL).
        foreach ($this->segmentColumns as $col) {
            DB::statement("ALTER TABLE doctor_examinations ALTER COLUMN {$col} TYPE text");
        }

        // 2. Field baru: palpebra (anterior) + 2 catatan segmen.
        Schema::table('doctor_examinations', function (Blueprint $table) {
            $table->text('sa_palpebra_od')->nullable()->after('anamnese');
            $table->text('sa_palpebra_os')->nullable()->after('sa_palpebra_od');
            $table->text('sa_notes')->nullable()->after('sa_lensa_os');
            $table->text('sp_notes')->nullable()->after('sp_vitreous_os');
        });
    }

    public function down(): void
    {
        Schema::table('doctor_examinations', function (Blueprint $table) {
            $table->dropColumn(['sa_palpebra_od', 'sa_palpebra_os', 'sa_notes', 'sp_notes']);
        });
        // Tipe text→varchar sengaja TIDAK dikembalikan (text superset; hindari truncation).
    }
};
