<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Selaraskan surgery_package_tariffs ke pola "insurer-only" (sama dengan
 * procedure/medication/bhp/iol_tariffs yang drop classification di 2026_05_26_000011
 * dan room_tariffs di 2026_06_08_000001).
 *
 * Identitas tarif paket berubah dari (surgery_package_id, insurer_id, classification)
 * menjadi (surgery_package_id, insurer_id). UMUM/BPJS/SOSIAL kini insurer sistem
 * (is_system=true) jadi classification redundant.
 *
 * KasirService::buildPaketLines() resolve harga jual paket via insurer_id
 * (TPA-aware) + fallback insurer_id NULL ("SEMUA"), TIDAK pakai classification.
 *
 * DEDUP: data lama bisa punya >1 baris per (package, insurer_id) yang dulu beda
 * hanya di classification → akan tabrakan unique baru. Sebelum drop kolom, sisakan
 * satu baris per (package, insurer_id): prioritaskan classification 'UMUM' (default
 * historis), lalu sell_price terbesar, lalu terbaru.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('surgery_package_tariffs', 'classification')) {
            $this->dedupeBeforeDrop();

            Schema::table('surgery_package_tariffs', function (Blueprint $t) {
                $t->dropUnique('surgery_package_tariffs_uniq');
                $t->dropIndex('surgery_package_tariffs_classification_index');
                $t->dropColumn('classification');
            });
            Schema::table('surgery_package_tariffs', function (Blueprint $t) {
                $t->unique(['surgery_package_id', 'insurer_id'], 'surgery_package_tariffs_uniq');
            });
        }
    }

    /**
     * Hapus duplikat (surgery_package_id, insurer_id) — sisakan 1 baris terbaik.
     * insurer_id NULL diperlakukan sebagai grup tersendiri ("SEMUA").
     */
    private function dedupeBeforeDrop(): void
    {
        $rows = DB::table('surgery_package_tariffs')
            ->whereNull('deleted_at')
            ->get(['id', 'surgery_package_id', 'insurer_id', 'classification', 'sell_price', 'created_at']);

        $keep    = [];   // key => row terpilih
        $deleteIds = [];

        foreach ($rows as $row) {
            $key = $row->surgery_package_id . '|' . ($row->insurer_id ?? 'NULL');

            if (! isset($keep[$key])) {
                $keep[$key] = $row;
                continue;
            }

            // Bandingkan kandidat dengan yang sudah disimpan; pilih yang lebih baik.
            $current = $keep[$key];
            if ($this->isBetter($row, $current)) {
                $deleteIds[] = $current->id;
                $keep[$key]  = $row;
            } else {
                $deleteIds[] = $row->id;
            }
        }

        if (! empty($deleteIds)) {
            DB::table('surgery_package_tariffs')->whereIn('id', $deleteIds)->delete();
        }
    }

    /** True kalau $a lebih layak dipertahankan daripada $b. */
    private function isBetter(object $a, object $b): bool
    {
        // 1. classification 'UMUM' diutamakan (default historis).
        $aUmum = ($a->classification ?? '') === 'UMUM';
        $bUmum = ($b->classification ?? '') === 'UMUM';
        if ($aUmum !== $bUmum) {
            return $aUmum;
        }
        // 2. sell_price terbesar.
        if ((float) $a->sell_price !== (float) $b->sell_price) {
            return (float) $a->sell_price > (float) $b->sell_price;
        }
        // 3. terbaru.
        return (string) $a->created_at > (string) $b->created_at;
    }

    public function down(): void
    {
        if (! Schema::hasColumn('surgery_package_tariffs', 'classification')) {
            Schema::table('surgery_package_tariffs', function (Blueprint $t) {
                $t->dropUnique('surgery_package_tariffs_uniq');
            });
            Schema::table('surgery_package_tariffs', function (Blueprint $t) {
                $t->string('classification', 20)->default('UMUM')->after('insurer_id');
                $t->index('classification');
                $t->unique(['surgery_package_id', 'insurer_id', 'classification'], 'surgery_package_tariffs_uniq');
            });
        }
    }
};
