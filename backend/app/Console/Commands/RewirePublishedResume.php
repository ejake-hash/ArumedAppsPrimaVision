<?php

namespace App\Console\Commands;

use App\Models\PatientDocument;
use App\Services\FormRegistry\FormRegistryService;
use Illuminate\Console\Command;

/**
 * Override/regenerasi dokumen Form Registry yang SUDAH terbit (FINALIZED) agar
 * memakai template + wiring/resolver TERKINI — mis. Resume Medis lama yang
 * ter-render saat binding autofill belum lengkap (sebelum FormTemplateSeeder
 * di-update). rendered_html ditulis ulang IN-PLACE: jawaban manual dokter
 * (static_payload) & tanda tangan dipertahankan + di-embed ulang, revision naik
 * + banner "REVISI ke-N".
 *
 * Default DRY-RUN (hanya daftar kandidat). Pakai --apply untuk benar-benar menulis.
 * WAJIB jalankan `db:seed --class=FormTemplateSeeder --force` LEBIH DULU agar
 * template aktif sudah versi terbaru.
 *
 * ⚠️ Hanya untuk koreksi BUG render (data klinis tak berubah). Untuk koreksi
 * substansi klinis pakai alur Revisi (versi baru + TTD ulang), bukan command ini.
 */
class RewirePublishedResume extends Command
{
    protected $signature = 'resume:rewire-published
                            {--apply : Tulis perubahan (default: dry-run preview saja)}
                            {--overwrite : Resolve ulang isi autofill (timpa static_payload) — bukan hanya isi field kosong}
                            {--code=RESUME_MEDIS : Kode template yang diregenerasi}
                            {--id= : Batasi ke satu patient_document_id tertentu}';

    protected $description = 'Regenerasi in-place dokumen final (default Resume Medis) dengan template/wiring terbaru, pertahankan TTD.';

    public function handle(FormRegistryService $svc): int
    {
        $apply     = (bool) $this->option('apply');
        $overwrite = (bool) $this->option('overwrite');
        $code      = (string) $this->option('code');
        $id        = $this->option('id');

        $query = PatientDocument::query()
            ->where('template_code', $code)
            ->whereIn('status', ['FINALIZED', 'FINAL'])
            ->with(['visit.patient']);
        if ($id) {
            $query->where('id', $id);
        }

        $docs = $query->orderBy('finalized_at')->get();

        if ($docs->isEmpty()) {
            $this->info("Tidak ada dokumen final dengan template_code={$code} untuk diregenerasi.");
            return self::SUCCESS;
        }

        $mode = ($apply ? '[APPLY' : '[DRY RUN') . ($overwrite ? '+OVERWRITE] ' : '] ');
        $this->info($mode . "Kandidat: {$docs->count()} dokumen (template={$code})."
            . ($overwrite ? ' Mode OVERWRITE: isi autofill di-resolve ulang & static_payload ditimpa.' : ''));
        $this->line(str_repeat('-', 92));

        $ok = 0; $fail = 0;
        foreach ($docs as $doc) {
            $patient = $doc->visit?->patient?->name ?? '—';
            $rm      = $doc->visit?->patient?->no_rm ?? '—';
            $label   = "doc={$doc->id} rev={$doc->revision} · {$patient} (RM {$rm}) · final={$doc->finalized_at}";

            if (!$apply) {
                $this->line('  • ' . $label);
                continue;
            }

            try {
                $new = $svc->regenerateFinalized($doc->id, $overwrite);
                $this->info("  ✓ {$label} → revisi {$new->revision} (" . strlen((string) $new->rendered_html) . " byte)");
                $ok++;
            } catch (\Throwable $e) {
                $this->error("  ✗ {$label} → {$e->getMessage()}");
                $fail++;
            }
        }

        $this->line(str_repeat('-', 92));
        if ($apply) {
            $this->info("Selesai: {$ok} berhasil, {$fail} gagal.");
        } else {
            $this->warn("DRY RUN — tidak ada yang ditulis. Jalankan ulang dengan --apply untuk menerapkan.");
        }

        return $fail > 0 ? self::FAILURE : self::SUCCESS;
    }
}
