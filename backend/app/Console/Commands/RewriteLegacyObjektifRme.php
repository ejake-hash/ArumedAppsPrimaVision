<?php

namespace App\Console\Commands;

use App\Models\DoctorExamination;
use App\Models\RefractionRecord;
use App\Services\RmeAggregatorService;
use Illuminate\Console\Command;

/**
 * Remediasi data: tulis-ulang "O" SOAP dokter hasil MIGRASI LEGACY
 * (MigrateFromPrimaVision::buildObjektifFromRefraksi — format lama
 * "Visus UCVA: … / Visus BCVA: … / IOP: … / Rx: …") ke skema O TERBARU
 * yang seragam dengan timeline CPPT refraksionis:
 *
 *   Visus awal OD … / OS …
 *   Visus akhir OD … / OS …
 *   TIO OD … / OS … mmHg
 *   PD … mm
 *   Autoref OD … | OS …      ← khusus legacy (data subjektif tak ada di sumber)
 *
 * Sumber utama = RefractionRecord visit yang sama (ikut termigrasi, terstruktur)
 * via RmeAggregatorService::refraksiObjektif; bila record tak ada, fallback
 * transformasi tekstual baris-per-baris (nilai persis dipertahankan).
 *
 * Target HANYA doctor_examinations ber-legacy_uuid yang SELURUH baris O-nya
 * berpola migrasi lama — O yang pernah diedit dokter / O entri app live
 * (segmen anterior/posterior) tidak akan cocok pola dan dilewati. Idempoten:
 * hasil rewrite tidak lagi berpola lama sehingga run berikutnya melewatinya.
 * Default DRY-RUN; tulis perubahan hanya dengan --apply.
 *
 *   php artisan rme:rewrite-o-legacy            (dry-run)
 *   php artisan rme:rewrite-o-legacy --visit=<uuid>
 *   php artisan rme:rewrite-o-legacy --apply
 */
class RewriteLegacyObjektifRme extends Command
{
    protected $signature = 'rme:rewrite-o-legacy
        {--visit= : Batasi ke satu visit (uuid)}
        {--limit= : Proses maksimal N entri (uji bertahap)}
        {--apply : Tulis perubahan (tanpa ini = dry-run)}';

    protected $description = 'Tulis-ulang O SOAP dokter hasil migrasi legacy ke skema O terbaru (selaras CPPT refraksionis)';

    private const POLA_LAMA = '/^(Visus UCVA: |Visus BCVA: |IOP: OD |Rx: OD )/u';

    public function handle(RmeAggregatorService $aggregator): int
    {
        $apply = (bool) $this->option('apply');
        $visit = $this->option('visit');

        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;

        $updated = 0;
        $bukanPolaLama = 0;
        $dariRecord = 0;
        $dariTeks = 0;
        $diproses = 0;
        $dikosongkan = 0;
        $gagal = [];

        DoctorExamination::query()
            ->whereNotNull('legacy_uuid')
            ->whereNotNull('soap_objective')
            ->where('soap_objective', '!=', '')
            ->when($visit, fn ($q) => $q->where('visit_id', $visit))
            ->chunkById(500, function ($chunk) use ($aggregator, $apply, $limit, &$updated, &$bukanPolaLama, &$dariRecord, &$dariTeks, &$diproses, &$dikosongkan, &$gagal) {
                // Prefetch record refraksi se-chunk (hindari 49rb query satuan).
                $recs = RefractionRecord::whereIn('visit_id', $chunk->pluck('visit_id'))->get()->keyBy('visit_id');

                foreach ($chunk as $de) {
                    if ($limit && $diproses >= $limit) {
                        return false;
                    }
                    $diproses++;

                    $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) $de->soap_objective))));
                    $polaLama = $lines !== [] && count(preg_grep(self::POLA_LAMA, $lines)) === count($lines);
                    if (! $polaLama) {
                        // Sudah skema baru / O app live (segmen) / pernah diedit dokter.
                        $bukanPolaLama++;
                        continue;
                    }

                    $rec  = $this->sanitasiRecord($recs->get($de->visit_id));
                    $baru = null;
                    if ($rec) {
                        $baru = $this->sisipkanAutoref($aggregator->refraksiObjektif($rec), $this->autorefLine($rec));
                        if ($baru) {
                            $dariRecord++;
                        }
                    }
                    if (! $baru) {
                        // Record refraksi tak ada / kosong → transformasi tekstual, nilai persis.
                        $baru = $this->transformTeks($lines);
                        if ($baru) {
                            $dariTeks++;
                        }
                    }
                    if (! $baru) {
                        // O lama murni placeholder (mis. "Visus BCVA: OD S-C-X- / OS S-C-X-")
                        // dan tak ada data nyata yang bisa dirakit → kosongkan saja,
                        // jangan biarkan teks sampah di rekam medis.
                        $dikosongkan++;
                        if ($apply) {
                            $de->soap_objective = null;
                            $de->save();
                        }
                        continue;
                    }

                    $updated++;
                    if ($updated <= 20) {
                        $this->line(($apply ? 'TULIS  ' : 'DRY    ') . $de->id . ' (visit ' . $de->visit_id . ') ← ' . ($rec ? 'derive record' : 'transform teks'));
                    } elseif ($updated === 21) {
                        $this->line('… (contoh selanjutnya tidak dicetak; lihat rekap di bawah)');
                    }
                    if ($apply) {
                        $de->soap_objective = $baru;
                        $de->save();
                    }
                }

                return ! ($limit && $diproses >= $limit);
            });

        $this->newLine();
        $this->table(['Hasil', 'Jumlah'], [
            ['Ditulis-ulang' . ($apply ? '' : ' (dry-run)'), $updated],
            ['  • derive dari RefractionRecord', $dariRecord],
            ['  • transformasi tekstual (record tak ada)', $dariTeks],
            ['Dikosongkan (O lama murni placeholder)', $dikosongkan],
            ['Dilewati (bukan pola migrasi lama)', $bukanPolaLama],
            ['Gagal dirakit — cek manual', count($gagal)],
        ]);
        foreach ($gagal as $id) {
            $this->warn("  cek manual: {$id}");
        }
        if (! $apply && $updated) {
            $this->info('Dry-run — jalankan ulang dengan --apply untuk menulis perubahan.');
        }

        return self::SUCCESS;
    }

    /**
     * Record refraksi visit ini dengan nilai PLACEHOLDER app lama dinetralkan
     * IN-MEMORY (tidak disimpan): visus "S-C-X"/"s x"/"s-c-x-" dst = template
     * kosong yang ikut termigrasi, bukan visus. "TTK"/"Tidak Terkoreksi"/"CF"/
     * "HM" dll TETAP (istilah klinis sah).
     */
    private function sanitasiRecord(?RefractionRecord $rec): ?RefractionRecord
    {
        if (! $rec) {
            return null;
        }
        foreach (['visus_awal_od', 'visus_awal_os', 'visus_akhir_od', 'visus_akhir_os', 'pinhole_od', 'pinhole_os'] as $f) {
            if ($this->isPlaceholder($rec->{$f})) {
                $rec->{$f} = null;
            }
        }

        return $rec;
    }

    /** Nilai placeholder template app lama: kombinasi huruf s/c/x + spasi/strip tanpa digit. */
    private function isPlaceholder($v): bool
    {
        if ($v === null || $v === '') {
            return false;
        }
        $norm = strtolower(preg_replace('/[\s\-]+/', '', (string) $v));

        return in_array($norm, ['scx', 'sx', 'sc', 'cx', 's', 'c', 'x'], true);
    }

    /** Sisipkan baris Autoref tepat setelah "Visus awal" (slot refraksi objektif). */
    private function sisipkanAutoref(?string $baseO, ?string $autoref): ?string
    {
        if (! $autoref) {
            return $baseO ? trim($baseO) : null;
        }
        $lines = $baseO ? explode("\n", trim($baseO)) : [];
        $pos = 0;
        foreach ($lines as $i => $l) {
            if (str_starts_with($l, 'Visus awal')) {
                $pos = $i + 1;
                break;
            }
        }
        array_splice($lines, $pos, 0, [$autoref]);

        return implode("\n", $lines);
    }

    /** Baris "Autoref OD … | OS …" dari field terstruktur; fallback raw_data legacy. */
    private function autorefLine(RefractionRecord $rec): ?string
    {
        // Format selaras fmtRx/memory: "S -1.00 / C -0.75 / X 150" (2 desimal, berspasi).
        $fmt = function ($sph, $cyl, $axis) {
            $sg = fn ($n) => ($n >= 0 ? '+' : '') . number_format((float) $n, 2, '.', '');
            $p = [];
            if ($sph !== null) {
                $p[] = 'S ' . $sg($sph);
            }
            if ($cyl !== null) {
                $p[] = 'C ' . $sg($cyl);
            }
            if ($axis !== null) {
                $p[] = 'X ' . (int) $axis;
            }

            return $p ? implode(' / ', $p) : null;
        };
        $od = $fmt($rec->autoref_od_sph, $rec->autoref_od_cyl, $rec->autoref_od_axis);
        $os = $fmt($rec->autoref_os_sph, $rec->autoref_os_cyl, $rec->autoref_os_axis);
        if ($od === null && $os === null) {
            // Parse gagal saat migrasi → string mentah app lama (apa adanya),
            // kecuali placeholder/"Error" yang jelas bukan hasil ukur.
            $raw = (array) ($rec->raw_data ?? []);
            $bersih = function ($v) {
                $v = is_string($v) ? trim($v) : null;
                if ($v === null || $v === '' || strcasecmp($v, 'error') === 0 || $this->isPlaceholder($v)) {
                    return null;
                }

                return $v;
            };
            $od = $bersih($raw['autoref_od'] ?? null);
            $os = $bersih($raw['autoref_os'] ?? null);
        }
        if ($od === null && $os === null) {
            return null;
        }

        return 'Autoref OD ' . ($od ?? '–') . ' | OS ' . ($os ?? '–');
    }

    /** Transformasi baris format migrasi lama → istilah skema baru (nilai persis,
     *  kecuali placeholder s-c-x/"Error" → buang; baris tanpa nilai tersisa drop). */
    private function transformTeks(array $lines): ?string
    {
        $out = [];
        foreach ($lines as $l) {
            $l = preg_replace(
                ['/^Visus UCVA: /u', '/^Visus BCVA: /u', '/^IOP: OD /u', '/^Rx: OD /u'],
                ['Visus awal ', 'Visus akhir ', 'TIO OD ', 'Autoref OD '],
                $l
            );
            // Netralkan nilai placeholder per-mata: "OD s-c-x / OS 6/9" → "OD – / OS 6/9".
            $l = preg_replace_callback('/(OD|OS) ([^\/|]+?)(?=\s*(?:\/|\||mmHg|$))/u', function ($m) {
                $v = trim($m[2]);
                if ($this->isPlaceholder($v) || strcasecmp($v, 'error') === 0) {
                    return $m[1] . ' –';
                }

                return $m[0];
            }, $l);
            // Baris yang kedua matanya kosong tidak informatif → drop.
            if (! preg_match('/(OD|OS) (?!–)\S/u', $l)) {
                continue;
            }
            $out[] = $l;
        }

        return $out ? implode("\n", $out) : null;
    }
}
