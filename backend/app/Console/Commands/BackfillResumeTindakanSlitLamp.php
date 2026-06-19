<?php

namespace App\Console\Commands;

use App\Models\PatientDocument;
use Illuminate\Console\Command;

/**
 * Tambahkan "Slit Lamp" ke baris auto tindakan Resume Medis Rawat Jalan pada
 * dokumen yang BELUM ditandatangani (DRAFT/RENDERED/PENDING_SIGNATURE). Dokumen
 * baru otomatis sudah benar (AggregateResolver::resolveTindakanRmrj diperbarui);
 * command ini hanya untuk berkas LAMA yang nilainya sudah ter-bake di
 * signatures.static_payload.tindakan.
 *
 * AMAN & idempoten:
 *  - hanya menyentuh nilai yang MASIH memuat frasa auto lama
 *    "Visus, Tonometri, Autorefkeratometri" dan BELUM memuat "Slit Lamp"
 *    → editan manual dokter / yang sudah benar TIDAK diutak-atik;
 *  - FINALIZED (sudah ber-TTD) DIKECUALIKAN (dokumen final tak boleh berubah);
 *  - rendered_html di-regenerate otomatis dari static_payload saat dokumen
 *    dibuka/difinalisasi, jadi cukup update static_payload (sumber kebenaran).
 *
 * Default DRY-RUN. Pakai --apply untuk benar-benar menyimpan.
 */
class BackfillResumeTindakanSlitLamp extends Command
{
    protected $signature = 'resume:tindakan-add-slitlamp
                            {--apply : Simpan perubahan (default: dry-run preview saja)}
                            {--code=RESUME_MEDIS : Kode template}';

    protected $description = 'Tambah "Slit Lamp" ke tindakan auto Resume Medis pada berkas belum-TTD (DRAFT/RENDERED/PENDING_SIGNATURE).';

    private const OLD = 'Visus, Tonometri, Autorefkeratometri';
    private const NEW = 'Visus, Tonometri, Autorefkeratometri, Slit Lamp';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $code  = (string) $this->option('code');

        $docs = PatientDocument::query()
            ->where('template_code', $code)
            ->whereIn('status', ['DRAFT', 'RENDERED', 'PENDING_SIGNATURE'])
            ->get();

        $mode = $apply ? '[APPLY]' : '[DRY RUN]';
        $this->info("{$mode} Periksa {$docs->count()} dokumen {$code} (belum-TTD)");

        $changed = 0; $skipped = 0;
        foreach ($docs as $doc) {
            $sig = is_array($doc->signatures) ? $doc->signatures : [];
            $sp  = $sig['static_payload'] ?? [];
            $tindakan = is_array($sp) ? ($sp['tindakan'] ?? null) : null;

            // Lewati: tak ada nilai tersimpan (DRAFT murni → auto saat dibuka),
            // sudah ada "Slit Lamp", atau tak memuat frasa auto lama (editan manual).
            if (!is_string($tindakan) || $tindakan === ''
                || str_contains($tindakan, 'Slit Lamp')
                || !str_contains($tindakan, self::OLD)) {
                $skipped++;
                continue;
            }

            $new = str_replace(self::OLD, self::NEW, $tindakan);
            $changed++;
            $this->line("  ✓ {$doc->id} ({$doc->status})");

            if ($apply) {
                $sp['tindakan'] = $new;
                $sig['static_payload'] = $sp;
                $doc->signatures = $sig;
                $doc->save();
            }
        }

        if ($apply) {
            $this->info("Selesai: diperbarui={$changed} dilewati={$skipped}");
        } else {
            $this->info("Dry-run: akan diperbarui={$changed} dilewati={$skipped}. Jalankan ulang dengan --apply untuk menyimpan.");
        }

        return self::SUCCESS;
    }
}
