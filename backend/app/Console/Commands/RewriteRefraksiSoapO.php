<?php

namespace App\Console\Commands;

use App\Models\RefractionRecord;
use Illuminate\Console\Command;

/**
 * Remediasi data: tulis-ulang SOAP O refraksionis (refraction_records.soap_o)
 * ke skema TERBARU — tonometri berulang (iop_extra_readings) ikut tampil
 * sebagai baris "TIO #2 OD … / OS … mmHg" di bawah baris TIO pertama.
 *
 * Skema lama hanya merangkai pengukuran #1 (iop_od/iop_os), jadi soap_o lama
 * kehilangan pengukuran ulang. Kandidat = record dengan iop_extra_readings
 * berisi. Baris TIO pertama yang sudah ada di soap_o TIDAK direkonstruksi
 * (nilai tulisan FE dipertahankan apa adanya, termasuk edit manual petugas) —
 * baris #2 dst hanya DISISIPKAN tepat di bawahnya.
 *
 * Aman dijalankan berulang (idempoten): soap_o yang sudah memuat "TIO #"
 * dianggap sudah skema baru dan dilewati. soap_o KOSONG juga dilewati —
 * tampilan CPPT/resume memakai fallback derive live yang sudah diperbarui.
 * Default DRY-RUN; tulis perubahan hanya dengan --apply.
 *
 *   php artisan refraksi:rewrite-soap-o            (dry-run)
 *   php artisan refraksi:rewrite-soap-o --record=<uuid>
 *   php artisan refraksi:rewrite-soap-o --apply
 */
class RewriteRefraksiSoapO extends Command
{
    protected $signature = 'refraksi:rewrite-soap-o
        {--record= : Batasi ke satu refraction_record (uuid)}
        {--apply : Tulis perubahan (tanpa ini = dry-run)}';

    protected $description = 'Tulis-ulang soap_o refraksionis lama ke skema baru (tonometri berulang ikut tampil)';

    public function handle(): int
    {
        $apply  = (bool) $this->option('apply');
        $record = $this->option('record');

        $kandidat = RefractionRecord::query()
            ->whereNotNull('iop_extra_readings')
            ->when($record, fn ($q) => $q->where('id', $record))
            ->orderBy('created_at')
            ->get();

        $updated = $sudahBaru = $kosong = $tanpaExtras = 0;
        $tanpaTio = [];

        foreach ($kandidat as $r) {
            $extraLines = $this->extraTioLines($r->iop_extra_readings);
            if ($extraLines === []) {
                $tanpaExtras++;
                continue;
            }

            $soapO = (string) $r->soap_o;
            if (trim($soapO) === '') {
                // Fallback derive live (RmeAggregator/DokterService) sudah skema baru.
                $kosong++;
                continue;
            }
            if (str_contains($soapO, 'TIO #')) {
                $sudahBaru++;
                continue;
            }

            // Sisipkan baris #2 dst tepat di bawah baris TIO pertama yang ada.
            $blok  = implode("\n", $extraLines);
            $baru  = preg_replace_callback(
                '/^(TIO OD [^\n]*)$/mu',
                fn ($m) => $m[1] . "\n" . $blok,
                $soapO,
                1,
                $count
            );
            if (! $count) {
                // Tak ada baris TIO di soap_o. Bila SEMUA baris yang ada merupakan
                // subset baris hasil derive (murni autofill lama yang membeku dini,
                // mis. cuma "PD 64 mm"), aman diregenerasi penuh dari data record.
                // Ada baris di luar derive = tulisan manual → jangan tebak, cek manual.
                $derived = app(\App\Services\RmeAggregatorService::class)->refraksiObjektif($r) ?? '';
                $derivedLines = array_map('trim', explode("\n", $derived));
                $soapLines    = array_filter(array_map('trim', preg_split('/\r?\n/', $soapO)));
                // Baris "PD … mm" dihitung autofill walau NILAINYA beda dgn record —
                // PD punya default '64' di form sehingga autofill bisa membeku di
                // nilai basi sebelum petugas mengubahnya (nilai benar = pd_distance).
                $sisa = array_filter(
                    array_diff($soapLines, $derivedLines),
                    fn ($l) => ! preg_match('/^PD\s+\d+([.,]\d+)?\s*mm$/u', $l)
                );
                $subsetDerive = $soapLines !== [] && $sisa === [];
                if (! $subsetDerive) {
                    $tanpaTio[] = $r->id;
                    continue;
                }
                $baru = $derived;
                $this->line(($apply ? 'TULIS  ' : 'DRY    ') . $r->id . "  REGEN penuh dari data record (soap_o lama murni autofill: \"" . str_replace("\n", ' | ', $soapO) . "\")");
            } else {
                $this->line(($apply ? 'TULIS  ' : 'DRY    ') . $r->id . '  +' . count($extraLines) . " baris TIO ulangan (visit {$r->visit_id})");
            }

            $updated++;
            if ($apply) {
                $r->soap_o = $baru;
                $r->save();
            }
        }

        $this->newLine();
        $this->table(['Hasil', 'Jumlah'], [
            ['Disisipkan baris TIO ulangan' . ($apply ? '' : ' (dry-run)'), $updated],
            ['Sudah skema baru (ada "TIO #")', $sudahBaru],
            ['soap_o kosong — fallback live sudah benar', $kosong],
            ['iop_extra_readings kosong/tak berisi', $tanpaExtras],
            ['Tanpa baris TIO di soap_o — cek manual', count($tanpaTio)],
        ]);
        foreach ($tanpaTio as $id) {
            $this->warn("  cek manual: {$id}");
        }
        if (! $apply && $updated) {
            $this->info('Dry-run — jalankan ulang dengan --apply untuk menulis perubahan.');
        }

        return self::SUCCESS;
    }

    /** Baris "TIO #N OD … / OS … mmHg" dari iop_extra_readings (skip baris kosong). */
    private function extraTioLines($readings): array
    {
        $lines = [];
        foreach (array_values((array) ($readings ?? [])) as $i => $x) {
            $od = $x['od'] ?? null;
            $os = $x['os'] ?? null;
            if (! $od && ! $os) {
                continue;
            }
            $f = fn ($v) => ($v === null || $v === '') ? '–' : (string) (is_numeric($v) ? + $v : $v);
            $lines[] = 'TIO #' . ($i + 2) . ' OD ' . $f($od) . ' / OS ' . $f($os) . ' mmHg';
        }

        return $lines;
    }
}
