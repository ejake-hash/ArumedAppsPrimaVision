<?php

namespace App\Console\Commands;

use App\Models\DoctorExamination;
use App\Models\RefractionRecord;
use Illuminate\Console\Command;

/**
 * Remediasi data CPPT lalu: buang nilai AUTOREF yang terlanjur tertulis sebagai baris
 * "Refraksi subjektif OD … | OS …" di O SOAP dokter (doctor_examinations.soap_objective)
 * hasil migrasi legacy.
 *
 * Latar: command lama `rme:rewrite-o-legacy` (subjektifLine) merakit baris subjektif dari
 * kolom AUTOREF (nilai objektif mesin) — keliru secara klinis: autoref ≠ refraksi subjektif.
 * Data legacy TIDAK PERNAH mengisi kolom refraksi_subjektif_* (selalu kosong), jadi baris
 * subjektif yang muncul di CPPT dokter kunjungan lama pasti bersumber autoref. Baris tsb
 * diganti menjadi "Refraksi subjektif (Kosong / Error)" selaras aturan derive terbaru
 * (RefraksionisView::oDerived, RmeAggregator::refraksiObjektif, dst.).
 *
 * Guard: HANYA doctor_examinations ber-legacy_uuid, dan HANYA bila kolom
 * refraksi_subjektif_* record refraksi terkait SELURUHNYA kosong (memastikan baris memang
 * autoref, bukan hasil ukur nyata yang mungkin diedit dokter). Baris "(Kosong / Error)"
 * yang sudah benar otomatis dilewati (filter LIKE + str_starts_with) → idempoten.
 * Default DRY-RUN; tulis hanya dengan --apply.
 *
 * Catatan: resume medis (static_payload terbit) TIDAK disentuh — hanya CPPT. Kartu CPPT
 * refraksionis tak perlu remediasi (soap_o legacy kosong → fallback live sudah benar).
 *
 *   php artisan rme:fix-subjektif-autoref-legacy            (dry-run)
 *   php artisan rme:fix-subjektif-autoref-legacy --visit=<uuid>
 *   php artisan rme:fix-subjektif-autoref-legacy --limit=100
 *   php artisan rme:fix-subjektif-autoref-legacy --apply
 */
class FixSubjektifAutorefLegacy extends Command
{
    protected $signature = 'rme:fix-subjektif-autoref-legacy
        {--visit= : Batasi ke satu visit (uuid)}
        {--limit= : Proses maksimal N entri (uji bertahap)}
        {--apply : Tulis perubahan (tanpa ini = dry-run)}';

    protected $description = 'Ganti baris "Refraksi subjektif" bersumber autoref (migrasi legacy) → "(Kosong / Error)" di O SOAP dokter';

    private const KOSONG = 'Refraksi subjektif (Kosong / Error)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $visit = $this->option('visit');
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;

        $diproses = 0;
        $diganti = 0;
        $adaSubjektifNyata = 0;
        $tanpaBarisAutoref = 0;

        DoctorExamination::query()
            ->whereNotNull('legacy_uuid')
            ->whereNotNull('soap_objective')
            ->where('soap_objective', 'like', '%Refraksi subjektif OD %')
            ->when($visit, fn ($q) => $q->where('visit_id', $visit))
            ->chunkById(500, function ($chunk) use ($apply, $limit, &$diproses, &$diganti, &$adaSubjektifNyata, &$tanpaBarisAutoref) {
                // Prefetch record refraksi se-chunk (hindari query satuan).
                $recs = RefractionRecord::whereIn('visit_id', $chunk->pluck('visit_id'))->get()->keyBy('visit_id');

                foreach ($chunk as $de) {
                    if ($limit && $diproses >= $limit) {
                        return false;
                    }
                    $diproses++;

                    $lines = explode("\n", (string) $de->soap_objective);
                    $idx = null;
                    foreach ($lines as $i => $l) {
                        if (str_starts_with(trim($l), 'Refraksi subjektif OD ')) {
                            $idx = $i;
                            break;
                        }
                    }
                    if ($idx === null) {
                        // Kemungkinan sudah "(Kosong / Error)" atau format lain — lewati.
                        $tanpaBarisAutoref++;
                        continue;
                    }

                    // Guard: hanya bila subjektif terstruktur record terkait KOSONG (baris = autoref).
                    $rec = $recs->get($de->visit_id);
                    if ($rec && $this->punyaSubjektifNyata($rec)) {
                        $adaSubjektifNyata++;
                        continue;
                    }

                    $lines[$idx] = self::KOSONG;
                    $diganti++;
                    if ($diganti <= 20) {
                        $this->line(($apply ? 'TULIS  ' : 'DRY    ') . $de->id . ' (visit ' . $de->visit_id . ')');
                    } elseif ($diganti === 21) {
                        $this->line('… (contoh selanjutnya tidak dicetak; lihat rekap di bawah)');
                    }
                    if ($apply) {
                        $de->soap_objective = implode("\n", $lines);
                        $de->save();
                    }
                }

                return ! ($limit && $diproses >= $limit);
            });

        $this->newLine();
        $this->table(['Hasil', 'Jumlah'], [
            ['Diproses (kandidat)', $diproses],
            ['Diganti → (Kosong / Error)' . ($apply ? '' : ' (dry-run)'), $diganti],
            ['Dilewati — subjektif nyata ada', $adaSubjektifNyata],
            ['Dilewati — baris autoref tak ditemukan', $tanpaBarisAutoref],
        ]);
        if (! $apply) {
            $this->warn('DRY-RUN. Tambahkan --apply untuk menulis perubahan.');
        }

        return self::SUCCESS;
    }

    /** True bila kolom refraksi subjektif terstruktur record berisi (mata mana pun). */
    private function punyaSubjektifNyata(RefractionRecord $rec): bool
    {
        foreach ([
            'refraksi_subjektif_od_sph', 'refraksi_subjektif_od_cyl', 'refraksi_subjektif_od_axis',
            'refraksi_subjektif_os_sph', 'refraksi_subjektif_os_cyl', 'refraksi_subjektif_os_axis',
        ] as $f) {
            if ($rec->{$f} !== null) {
                return true;
            }
        }

        return false;
    }
}
