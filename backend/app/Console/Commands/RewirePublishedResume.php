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
                            {--include-drafts : Ikut normalisasi dokumen BELUM final (payload draft diganti autofill segar + manual_fields direset)}
                            {--code=RESUME_MEDIS : Kode template yang diregenerasi}
                            {--id= : Batasi ke satu patient_document_id tertentu}
                            {--only-contaminated-anamnese : Hanya dokumen yang Anamnese-nya tercemar baris ICD-9/visus/IOP (TTV/TOD/VOD/mmHg) — sisanya dilewati}';

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
        $this->scopeContaminated($query);

        $docs = $query->orderBy('finalized_at')->get();

        if ($docs->isEmpty()) {
            $this->info("Tidak ada dokumen final dengan template_code={$code} untuk diregenerasi.");
            // Jangan berhenti bila --include-drafts: bagian draft di bawah tetap jalan.
            if (! $this->option('include-drafts')) {
                return self::SUCCESS;
            }
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

        // ── Dokumen BELUM final (DRAFT/RENDERED/PENDING_SIGNATURE) — opsional ────
        // Payload draft lama bisa berisi nilai autofill versi LAMA yang non-kosong
        // (mis. kode penunjang nyangkut di kolom Tindakan) → menang saat render &
        // dianggap manual oleh heuristik prefill. Normalisasi: payload = autofill segar.
        if ($this->option('include-drafts')) {
            $draftQuery = PatientDocument::query()
                ->where('template_code', $code)
                ->whereNotIn('status', ['FINALIZED', 'FINAL', 'SUPERSEDED'])
                ->with(['visit.patient']);
            if ($id) {
                $draftQuery->where('id', $id);
            }
            $this->scopeContaminated($draftQuery);
            $drafts = $draftQuery->orderBy('created_at')->get();

            $this->info(($apply ? '[APPLY] ' : '[DRY RUN] ') . "Draft belum final: {$drafts->count()} dokumen (payload → autofill segar).");
            foreach ($drafts as $doc) {
                $patient = $doc->visit?->patient?->name ?? '—';
                $rm      = $doc->visit?->patient?->no_rm ?? '—';
                $label   = "doc={$doc->id} status={$doc->status} · {$patient} (RM {$rm})";

                if (!$apply) {
                    $this->line('  • ' . $label);
                    continue;
                }
                try {
                    $svc->rewireDraftPayload($doc->id);
                    $this->info("  ✓ {$label} → payload disegarkan");
                    $ok++;
                } catch (\Throwable $e) {
                    $this->error("  ✗ {$label} → {$e->getMessage()}");
                    $fail++;
                }
            }
            $this->line(str_repeat('-', 92));
        }

        if ($apply) {
            $this->info("Selesai: {$ok} berhasil, {$fail} gagal.");
        } else {
            $this->warn("DRY RUN — tidak ada yang ditulis. Jalankan ulang dengan --apply untuk menerapkan.");
        }

        return $fail > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Batasi query ke dokumen yang Anamnese (static_payload) tercemar konten objektif:
     * baris "Tindakan/Prosedur (ICD-9): …" (oAutoText baru) ATAU pola TTV/Visus/IOP
     * (TOD/TOS/VOD/VOS/mmHg, format O lama). Idempoten — setelah dibersihkan tak cocok
     * lagi. No-op bila opsi tidak diberikan.
     */
    private function scopeContaminated($query): void
    {
        if (! $this->option('only-contaminated-anamnese')) {
            return;
        }
        $path = "signatures->'static_payload'->>'anamnese'";
        $query->where(function ($q) use ($path) {
            $q->whereRaw("$path ILIKE ?", ['%Tindakan/Prosedur (ICD-9)%'])
              ->orWhereRaw("$path ~ ?", ['VOD|TOD|TOS|VOS|mmHg']);
        });
    }
}
