<?php

namespace App\Console\Commands;

use App\Models\PatientDocument;
use App\Models\Visit;
use App\Services\FormRegistry\AggregateResolver;
use App\Services\FormRegistry\FormRegistryService;
use Illuminate\Console\Command;

/**
 * Perbaiki Resume Medis FINALIZED yang field "Pemeriksaan Fisik"-nya kehilangan
 * data refraksi (visus/TIO/Rx) — akibat soap_o tersimpan sepotong sehingga resolver
 * lama (sebelum fix b96b96a) tak memakai kolom terstruktur. Data refraksi SELALU ada
 * di kolom; ini koreksi TAMPILAN, bukan menambah data baru.
 *
 * BEDAH & aman:
 *  - hanya menyentuh dokumen yang Pemeriksaan Fisik-nya TIDAK memuat "Visus"/"TIO"
 *    PADAHAL resolver (terbaru) bisa menghasilkannya dari kolom → kasus rusak saja;
 *  - hanya field `pemeriksaan_fisik` yang diganti (editan manual field lain DIJAGA);
 *  - regenerateFinalized(overwrite:false) me-render ulang dari static_payload yang
 *    sudah dipatch → TTD + QR dipertahankan, revisi di-bump + banner revisi (audit);
 *  - dokumen non-FINALIZED tak disentuh (mereka re-resolve sendiri saat dibuka).
 *
 * Default DRY-RUN. Pakai --apply untuk benar-benar memperbaiki.
 */
class BackfillResumePemeriksaanFisik extends Command
{
    protected $signature = 'resume:fix-pemfis-refraksi
                            {--apply : Simpan & regenerate (default: dry-run preview saja)}
                            {--code=RESUME_MEDIS : Kode template}
                            {--limit=0 : Batasi jumlah dokumen (0 = semua)}';

    protected $description = 'Perbaiki Pemeriksaan Fisik Resume Medis FINALIZED yang kehilangan data refraksi (regenerate, TTD dipertahankan).';

    public function handle(AggregateResolver $resolver, FormRegistryService $svc): int
    {
        $apply = (bool) $this->option('apply');
        $code  = (string) $this->option('code');
        $limit = (int) $this->option('limit');

        $q = PatientDocument::query()
            ->where('template_code', $code)
            ->whereIn('status', ['FINALIZED', 'FINAL'])
            ->orderBy('created_at');
        if ($limit > 0) { $q->limit($limit); }
        $docs = $q->get();

        $mode = $apply ? '[APPLY]' : '[DRY RUN]';
        $this->info("{$mode} Periksa {$docs->count()} resume {$code} FINALIZED");

        $fixed = 0; $skipped = 0; $failed = 0;
        foreach ($docs as $doc) {
            if (!$doc->visit_id) { $skipped++; continue; }

            $stored = (string) data_get($doc->signatures, 'static_payload.pemeriksaan_fisik', '');
            $storedHasRef = (bool) preg_match('/Visus|TIO/i', $stored);

            $visit = Visit::find($doc->visit_id);
            $fresh = $visit ? (string) $resolver->resolve($visit, 'physical_exam', null) : '';
            $freshHasRef = (bool) preg_match('/Visus|TIO/i', $fresh);

            // Hanya kasus rusak: stored tanpa refraksi, fresh punya refraksi.
            if ($storedHasRef || !$freshHasRef || trim($fresh) === '' || $fresh === $stored) {
                $skipped++;
                continue;
            }

            $fixed++;
            $this->line("  ✓ {$doc->id} (rev " . ($doc->revision ?? 0) . ")");
            $this->line("      lama : " . str_replace("\n", ' / ', mb_substr($stored, 0, 120)));
            $this->line("      baru : " . str_replace("\n", ' / ', mb_substr($fresh, 0, 120)));

            if ($apply) {
                try {
                    // Patch HANYA pemeriksaan_fisik (jaga field lain), lalu re-render in-place.
                    $sig = is_array($doc->signatures) ? $doc->signatures : [];
                    $sp  = $sig['static_payload'] ?? [];
                    $sp['pemeriksaan_fisik'] = $fresh;
                    $sig['static_payload'] = $sp;
                    $doc->signatures = $sig;
                    $doc->save();

                    $svc->regenerateFinalized($doc->id, false);
                } catch (\Throwable $e) {
                    $failed++; $fixed--;
                    $this->error("  ✗ {$doc->id} → " . $e->getMessage());
                }
            }
        }

        if ($apply) {
            $this->info("Selesai: diperbaiki={$fixed} dilewati={$skipped} gagal={$failed}");
        } else {
            $this->info("Dry-run: akan diperbaiki={$fixed} dilewati={$skipped}. Jalankan ulang dengan --apply.");
        }

        return self::SUCCESS;
    }
}
