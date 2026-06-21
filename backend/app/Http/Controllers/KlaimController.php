<?php

namespace App\Http\Controllers;

use App\Models\Icd10Code;
use App\Models\Icd9Code;
use App\Services\KlaimService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class KlaimController extends Controller
{
    public function __construct(private readonly KlaimService $service) {}

    // =========================================================================
    // LIST & DETAIL
    // =========================================================================

    /**
     * GET /klaim
     * Query: status, search, tanggal_from, tanggal_to, per_page
     */
    public function index(Request $request): JsonResponse
    {
        return $this->ok($this->service->getClaimList(
            $request->only(['status', 'search', 'tanggal_from', 'tanggal_to', 'jenis_pelayanan', 'per_page'])
        ));
    }

    /** PUT /klaim/{id}/assign — tandai/lepas penanggung jawab (body: assigned_to_id|null) */
    public function assign(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'assigned_to_id' => 'nullable|uuid|exists:users,id',
        ]);

        try {
            $claim = $this->service->assignClaim($id, $request->input('assigned_to_id'));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $this->statusFor($e));
        }

        return $this->ok($claim, $request->input('assigned_to_id') ? 'Klaim ditandai dikerjakan' : 'Penanda dilepas');
    }

    /** GET /klaim/{id} — diperkaya label ICD + total billing untuk KlaimView. */
    public function show(string $id): JsonResponse
    {
        $claim = $this->service->getClaimById($id);

        // Label ICD HANYA untuk kode pada klaim ini (DB klaim simpan kode telanjang).
        // Hindari memuat SELURUH tabel ICD (~14k baris) tiap buka detail / polling 12 dtk.
        $dxCodes = collect([$claim->diagnosis_utama])
            ->merge(collect($claim->diagnosis_sekunder ?? [])->map(fn ($c) => is_array($c) ? ($c['kode'] ?? $c['code'] ?? null) : $c))
            ->filter()->unique()->values();
        $pxCodes = collect($claim->procedure_codes ?? [])
            ->map(fn ($c) => is_array($c) ? ($c['kode'] ?? $c['code'] ?? null) : $c)
            ->filter()->unique()->values();
        $icd10   = Icd10Code::whereIn('code', $dxCodes)->pluck('description', 'code');
        $icd10Id = Icd10Code::whereIn('code', $dxCodes)->pluck('indonesian_description', 'code');
        $icd9    = Icd9Code::whereIn('code', $pxCodes)->pluck('description', 'code');
        $icd9Id  = Icd9Code::whereIn('code', $pxCodes)->pluck('indonesian_description', 'code');

        // Collection::get() aman bila kode tak ada (hindari "Undefined array key").
        $dx10 = fn ($code) => $code
            ? ['kode' => $code, 'label' => ($icd10Id->get($code) ?: $icd10->get($code)) ?? $code]
            : null;
        $dx9 = fn ($code) => $code
            ? ['kode' => $code, 'label' => ($icd9Id->get($code) ?: $icd9->get($code)) ?? $code]
            : null;

        $data = $claim->toArray();
        $data['diagnosis_utama_obj'] = $dx10($claim->diagnosis_utama);
        $data['diagnosis_sekunder_obj'] = collect($claim->diagnosis_sekunder ?? [])
            ->map(fn ($c) => $dx10(is_array($c) ? ($c['kode'] ?? $c['code'] ?? null) : $c))
            ->filter()->values();
        $data['tindakan_obj'] = collect($claim->procedure_codes ?? [])
            ->map(fn ($c) => $dx9(is_array($c) ? ($c['kode'] ?? $c['code'] ?? null) : $c))
            ->filter()->values();
        $data['total_billing'] = $claim->visit?->billingInvoice?->total ?? 0;
        $data['jenis_pelayanan'] = $claim->visit?->jenis_pelayanan ?? 'RAJAL';
        // Diagnosa naratif (teks bebas dokter saat ragu kode ICD) — dibaca langsung
        // dari RME terbaru, tidak disalin ke bpjs_claims.
        $data['diagnosis_text'] = $claim->visit?->doctorExamination?->diagnosis_text;
        $data['assigned_to'] = $claim->assignedTo ? [
            'id'   => $claim->assignedTo->id,
            'name' => $claim->assignedTo->name,
        ] : null;

        // Penanda pipeline berkas → mengaktifkan aksi "Kembalikan ke Rekap" di
        // workspace Berkas Klaim (hanya bila kunjungan sudah dikirim ke klaim).
        $data['klaim_sent_at']     = $claim->visit?->klaim_sent_at?->toIso8601String();
        $data['klaim_returned_at'] = $claim->visit?->klaim_returned_at?->toIso8601String();

        // Lembar Klaim (Resume Medis versi klaim) — status TTD dokter + sinkron koding.
        $data['lembar_klaim'] = $this->service->claimResumeStatus($id);

        // Dokumen pendukung = PatientDocument pada visit klaim (FINAL diutamakan).
        // Sembunyikan dokumen arsip/void (selaras getBpjsVisitRecap) supaya verifikator
        // tak salah anggap berkas lengkap. NULLS LAST: dokumen final (punya tgl) di atas,
        // draft (finalized_at null) di bawah — bukan NULLS FIRST default Postgres.
        $data['dokumen_pendukung'] = \App\Models\PatientDocument::with('documentType')
            ->where('visit_id', $claim->visit_id)
            ->whereNotIn('status', \App\Services\KlaimService::DOC_ARCHIVED_STATUSES)
            ->orderByRaw('finalized_at DESC NULLS LAST')
            ->get()
            ->map(fn ($d) => [
                'id'       => $d->id,
                'nama'     => $d->documentType?->name ?? $d->template_code ?? 'Dokumen',
                'kode'     => $d->documentType?->code ?? $d->template_code,
                'nomor'    => $d->document_number,
                'status'   => $d->status,
                'tanggal'  => $d->finalized_at?->toDateString() ?? $d->created_at?->toDateString(),
            ]);

        return $this->ok($data);
    }

    // =========================================================================
    // PREPARE
    // =========================================================================

    /**
     * POST /klaim/grouping (sebenarnya prepare — buat klaim dari kunjungan)
     * Body: { visit_id }
     *
     * Ambil data dari:
     *   - visit.no_sep
     *   - doctorExamination.diagnosis_utama / sekunder / tindakan_codes
     *   - visit.patient.nik
     */
    public function runGrouping(Request $request): JsonResponse
    {
        $request->validate([
            'visit_id' => 'required|uuid|exists:visits,id',
        ]);

        try {
            $claim = $this->service->prepareClaimData($request->visit_id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $this->statusFor($e));
        }

        return $this->ok($claim, 'Data klaim disiapkan dari kunjungan', 201);
    }

    // =========================================================================
    // INA-CBGs GROUPING
    // =========================================================================

    /**
     * POST /klaim/{id}/grouping
     * Jalankan INA-CBGs grouper → dapatkan CBG code + tarif + severity.
     * Placeholder saat ini; engine JAR/API diintegrasikan terpisah.
     */
    public function runInaCbgsGrouping(string $id): JsonResponse
    {
        try {
            $claim = $this->service->runInaCbgsGrouping($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $this->statusFor($e));
        }

        return $this->ok($claim, "Grouping selesai. CBG: {$claim->inacbgs_kode}");
    }

    /** GET /klaim/grouping-log/{klaimId} */
    public function groupingLog(string $klaimId): JsonResponse
    {
        return $this->ok($this->service->getGroupingLog($klaimId));
    }

    // =========================================================================
    // WORKFLOW STATUS
    // =========================================================================

    /** PUT /klaim/{id}/review → DRAFT → REVIEW */
    public function setReview(string $id): JsonResponse
    {
        try {
            $claim = $this->service->setReview($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $this->statusFor($e));
        }

        return $this->ok($claim, 'Klaim dalam proses review');
    }

    /** PUT /klaim/{id}/verifikasi → REVIEW → VERIFIED */
    public function setVerifikasi(string $id): JsonResponse
    {
        try {
            $claim = $this->service->setVerifikasi($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $this->statusFor($e));
        }

        return $this->ok($claim, 'Klaim terverifikasi. Siap disubmit.');
    }

    /** PUT /klaim/{id}/reject — dikembalikan verifikator internal (→ DIKEMBALIKAN) */
    public function setReject(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'alasan' => 'required|string|min:5|max:500',
        ]);

        try {
            $claim = $this->service->setReject($id, $request->alasan);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $this->statusFor($e));
        }

        return $this->ok($claim, 'Klaim dikembalikan untuk perbaikan');
    }

    /** POST /klaim/{id}/resubmit — ajukan ulang klaim yang dikembalikan/ditolak (→ DRAFT) */
    public function resubmitKlaim(string $id): JsonResponse
    {
        try {
            $claim = $this->service->resubmitClaim($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $this->statusFor($e));
        }

        return $this->ok($claim, 'Klaim diajukan ulang. Perbaiki data lalu jalankan grouping → LUPIS → verifikasi.');
    }

    // =========================================================================
    // KODING — edit diagnosis/tindakan klaim + pencarian ICD
    // =========================================================================

    /**
     * GET /klaim/icd-search?type=icd10|icd9&q=...
     * Pencarian ICD untuk modal koding klaim (auth saja, tanpa permission master).
     */
    public function icdSearch(Request $request): JsonResponse
    {
        $type = $request->query('type', 'icd10');
        $q    = trim((string) $request->query('q', ''));

        if (mb_strlen($q) < 2) {
            return $this->ok([]); // hindari query terlalu lebar
        }

        $model = $type === 'icd9' ? Icd9Code::query() : Icd10Code::query();
        $rows  = $model
            ->where(fn ($w) => $w
                ->where('code', 'ilike', "%{$q}%")
                ->orWhere('description', 'ilike', "%{$q}%")
                ->orWhere('indonesian_description', 'ilike', "%{$q}%"))
            ->orderByRaw("CASE WHEN code ILIKE ? THEN 0 ELSE 1 END", ["{$q}%"]) // prefix dulu
            ->limit(25)
            ->get(['code', 'description', 'indonesian_description'])
            ->map(fn ($r) => [
                'kode'  => $r->code,
                'label' => $r->indonesian_description ?: $r->description,
            ]);

        return $this->ok($rows);
    }

    /**
     * PUT /klaim/{id}/diagnosis
     * Koreksi koding klaim (diagnosis utama/sekunder + tindakan) oleh verifikator/koder.
     * Tidak mengubah rekam medis Dokter — hanya kolom klaim. Mereset grouping.
     */
    public function updateDiagnosis(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'diagnosis_utama'              => 'required|string|max:10',
            'diagnosis_sekunder'          => 'array',
            'diagnosis_sekunder.*'        => 'string|max:10',
            'procedure_codes'             => 'array',
            'procedure_codes.*'           => 'string|max:10',
        ]);

        try {
            $claim = $this->service->updateClaimCoding($id, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $this->statusFor($e));
        }

        return $this->ok($claim, 'Koding klaim diperbarui. Jalankan ulang grouping INA-CBGs.');
    }

    // =========================================================================
    // LUPIS
    // =========================================================================

    /**
     * POST /klaim/{id}/lupis
     * Generate format LUPIS dari data klaim + billing.
     */
    public function generateLupis(string $id): JsonResponse
    {
        try {
            $claim = $this->service->generateLupis($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $this->statusFor($e));
        }

        return $this->ok($claim, 'Data LUPIS berhasil di-generate');
    }

    // =========================================================================
    // E-KLAIM INA-CBG (Web Service ws.php)
    // =========================================================================

    /** POST /klaim/{id}/eklaim/new — new_claim ke E-Klaim */
    public function eklaimNewClaim(string $id): JsonResponse
    {
        try {
            $res = $this->service->eklaimNewClaim($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $this->statusFor($e));
        }

        return $this->ok($res, $res['message'] ?? 'Klaim diregistrasi ke E-Klaim');
    }

    /** POST /klaim/{id}/eklaim/set-data — set_claim_data (builder payload) */
    public function eklaimSetData(string $id): JsonResponse
    {
        try {
            $res = $this->service->eklaimSetData($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $this->statusFor($e));
        }

        return $this->ok($res, $res['message'] ?? 'Data klaim dikirim ke E-Klaim');
    }

    /** POST /klaim/{id}/eklaim/grouper — jalankan grouper E-Klaim (body: stage?) */
    public function eklaimGrouper(Request $request, string $id): JsonResponse
    {
        // Stage WS E-Klaim hanya 1 (grouper) / 2 (special CMG). Tolak nilai liar.
        $request->validate(['stage' => 'nullable|integer|in:1,2']);
        $stage = (int) $request->input('stage', 1);

        try {
            $claim = $this->service->eklaimGrouper($id, $stage);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $this->statusFor($e));
        }

        return $this->ok($claim, "Grouper E-Klaim selesai. CBG: {$claim->inacbgs_kode}");
    }

    /** POST /klaim/{id}/eklaim/final — claim_final (irreversible) */
    public function eklaimFinal(string $id): JsonResponse
    {
        try {
            $claim = $this->service->eklaimFinal($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $this->statusFor($e));
        }

        return $this->ok($claim, 'Klaim difinalisasi di E-Klaim. Status: SUBMITTED.');
    }

    /** GET /klaim/{id}/eklaim/status — get_claim_status */
    public function eklaimStatus(string $id): JsonResponse
    {
        try {
            $res = $this->service->eklaimStatus($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $this->statusFor($e));
        }

        return $this->ok($res, $res['message'] ?? 'Status klaim E-Klaim');
    }

    /** POST /klaim/{id}/eklaim/reedit — reedit_claim (buka klaim final) */
    public function eklaimReedit(string $id): JsonResponse
    {
        try {
            $claim = $this->service->eklaimReedit($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $this->statusFor($e));
        }

        return $this->ok($claim, 'Klaim dibuka kembali dari E-Klaim untuk koreksi (DRAFT).');
    }

    /** POST /klaim/{id}/eklaim/kirim-online — Kirim Klaim Online ke DC Kemenkes/BPJS */
    public function eklaimKirimOnline(string $id): JsonResponse
    {
        try {
            $res = $this->service->sendClaimOnline($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $this->statusFor($e));
        }

        return $this->ok($res, $res['message'] ?? 'Klaim dikirim online.');
    }

    /** GET /klaim/{id}/eklaim/sync-dc — sinkron status pengiriman DC (read-only) */
    public function eklaimSyncDc(string $id): JsonResponse
    {
        try {
            $res = $this->service->syncDcStatus($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $this->statusFor($e));
        }

        return $this->ok($res, $res['terkirim'] ? 'Klaim sudah terkirim ke DC Kemenkes.' : 'Status DC disinkronkan.');
    }

    /** GET /klaim/{id}/cetak — Berkas Klaim Individual Pasien (replika cetak E-Klaim) → PDF */
    public function cetakKlaim(string $id)
    {
        try {
            $data = $this->service->buildBerkasKlaimPrintData($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $this->statusFor($e));
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.klaim-individual', $data)
            ->setPaper('a4')
            ->setOption('isRemoteEnabled', true);

        $safe = preg_replace('/[^A-Za-z0-9_-]/', '-', (string) ($data['no_sep'] ?? 'KLAIM')) ?: 'KLAIM';

        return $pdf->stream("BERKAS-KLAIM-{$safe}.pdf");
    }

    // =========================================================================
    // SUBMIT
    // =========================================================================

    /**
     * POST /klaim/{id}/submit
     * Submit ke VClaim API (harus VERIFIED + VClaim enabled).
     * Placeholder — actual VClaim call ada di IntegrasiService.
     */
    public function submitKlaim(string $id): JsonResponse
    {
        try {
            $claim = $this->service->submitClaim($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $this->statusFor($e));
        }

        return $this->ok($claim, 'Klaim berhasil disubmit ke VClaim. Status: SUBMITTED.');
    }

    // =========================================================================
    // AUDIT LOG & MONITORING
    // =========================================================================

    /** GET /klaim/{id}/audit-log */
    public function auditLog(string $id): JsonResponse
    {
        try {
            $logs = $this->service->getAuditLog($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $this->statusFor($e, 404));
        }

        return $this->ok($logs);
    }

    /**
     * GET /klaim/vclaim-log
     * Query: action, status, per_page
     */
    public function vclaimpLog(Request $request): JsonResponse
    {
        return $this->ok($this->service->getVclaimLog(
            $request->only(['action', 'status', 'per_page'])
        ));
    }

    /**
     * GET /klaim/icare/monitoring
     * Placeholder — aktif saat integrasi iCare dikonfigurasi.
     */
    public function icareMonitoring(): JsonResponse
    {
        try {
            $data = $this->service->icareMonitoring();
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $this->statusFor($e, 503));
        }

        return $this->ok($data);
    }

    // =========================================================================
    // LAMPIRAN BERKAS KLAIM (upload PDF/gambar)
    // =========================================================================

    /** GET /klaim/{id}/lampiran */
    public function attachments(string $id): JsonResponse
    {
        try {
            return $this->ok($this->service->getAttachments($id));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $this->statusFor($e));
        }
    }

    /** POST /klaim/{id}/lampiran (multipart: file, category, title?) */
    public function uploadAttachment(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'file'     => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240', // 10 MB
            'category' => 'nullable|string|max:30',
            'title'    => 'nullable|string|max:200',
        ]);

        try {
            $att = $this->service->uploadAttachment($id, $request->only(['category', 'title']), $request->file('file'));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $this->statusFor($e));
        }

        return $this->ok($att, 'Lampiran berhasil diunggah', 201);
    }

    /** DELETE /klaim/{id}/lampiran/{attId} */
    public function deleteAttachment(string $id, string $attId): JsonResponse
    {
        try {
            $this->service->deleteAttachment($id, $attId);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $this->statusFor($e));
        }

        return $this->ok(null, 'Lampiran dihapus');
    }

    // =========================================================================
    // BERKAS KLAIM (Vedika) — render dokumen/kwitansi → PDF untuk dirakit FE
    // jadi 1 PDF gabungan per pasien (siap unggah ke verifikasi digital BPJS).
    // =========================================================================

    /**
     * Sesuaikan rendered_html (dibuat untuk browser) agar aman dirender dompdf.
     * dompdf TIDAK andal merender <svg> inline (apalagi di dalam flexbox), sehingga
     * QR/barcode stempel TTD hilang di berkas Vedika. Solusi: ubah <svg class="rm-qr">
     * menjadi <img data-URI> (pola yang sama dgn cetak SEP yang sudah jalan di dompdf).
     * Plus paksa font DejaVu Sans pada glyph centang ✓ (Arial→Helvetica tak punya
     * U+2713 → tampil "?"). Berlaku untuk dokumen lama maupun baru.
     */
    private function dompdfSafeHtml(string $html): string
    {
        // (1) QR verifikasi/stempel: <svg class="rm-qr">…</svg> → <img data-URI>.
        $html = preg_replace_callback(
            '/<svg\b[^>]*\brm-qr\b[^>]*>.*?<\/svg>/s',
            function ($m) {
                $svg = $m[0];
                $w = preg_match('/\bwidth=["\']?(\d+)/', $svg, $mm) ? (int) $mm[1] : 44;
                return '<img src="data:image/svg+xml;base64,' . base64_encode($svg)
                    . '" style="width:' . $w . 'px;height:' . $w . 'px;display:inline-block;vertical-align:middle;" alt="QR" />';
            },
            $html
        ) ?? $html;

        // (2) Glyph centang pada stempel TTD elektronik → paksa DejaVu Sans.
        $html = str_replace(
            '✓ DITANDATANGANI SECARA ELEKTRONIK',
            '<span style="font-family:\'DejaVu Sans\',sans-serif;">&#10003;</span> DITANDATANGANI SECARA ELEKTRONIK',
            $html
        );

        return $html;
    }

    /** Bungkus rendered_html dengan shell A4 (selaras printHtml() FE) untuk dompdf. */
    private function wrapPrintHtml(string $html, string $title = 'Dokumen'): string
    {
        $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

        return '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>' . $t . '</title>'
            . '<style>@page{size:A4;margin:14mm}'
            . 'body{margin:0;font-family:"DejaVu Sans",Arial,sans-serif;font-size:12px;color:#1a1a1a}'
            . 'table{border-collapse:collapse}img{max-width:100%}</style></head><body>'
            . $html . '</body></html>';
    }

    /** GET /klaim/dokumen/{docId}/pdf — render snapshot dokumen RM (rendered_html) → PDF. */
    public function dokumenPdf(string $docId)
    {
        try {
            $snap = app(\App\Services\FormRegistry\FormRegistryService::class)->getSnapshot($docId);
        } catch (\Throwable $e) {
            return $this->error('Dokumen tidak ditemukan.', 404);
        }

        $html = $snap['rendered_html'] ?? null;
        if (! $html) {
            return $this->error('Dokumen belum punya snapshot (belum dirender/ditandatangani).', 422);
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($this->wrapPrintHtml($this->dompdfSafeHtml($html), $snap['template_code'] ?? 'Dokumen'))
            ->setPaper('a4')
            ->setOption('isRemoteEnabled', true);

        return $pdf->stream("DOK-{$docId}.pdf");
    }

    /**
     * GET /klaim/kwitansi/{visitId}/pdf — render kwitansi/tagihan kunjungan → PDF.
     * Pakai blade dompdf-safe `pdf.receipt` (tabel), BUKAN `receipt_print` (flexbox,
     * untuk browser) yang rusak di dompdf — selaras Mail\ReceiptMail.
     */
    public function kwitansiPdf(string $visitId)
    {
        $visit = \App\Models\Visit::with('billingInvoice')->find($visitId);
        if (! $visit?->billingInvoice) {
            return $this->error('Belum ada kwitansi/tagihan untuk kunjungan ini.', 404);
        }

        try {
            $data = app(\App\Services\KasirService::class)->generateReceipt($visit->billingInvoice->id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $this->statusFor($e));
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.receipt', $data)
            ->setPaper('a4')
            ->setOption('isRemoteEnabled', true);

        return $pdf->stream("KWITANSI-{$visitId}.pdf");
    }

    // =========================================================================
    // REKAP KUNJUNGAN BPJS — screening pra-klaim
    // =========================================================================

    /**
     * GET /klaim/rekap
     * Query: tanggal | tanggal_from + tanggal_to, search, per_page, page
     */
    public function rekap(Request $request): JsonResponse
    {
        return $this->ok($this->service->getBpjsVisitRecap(
            // 'only_sent' WAJIB diteruskan: tab "DIVA & Berkas" KlaimView kirim
            // only_sent=1 agar hanya kunjungan yang sudah "Kirim ke Klaim"
            // (klaim_sent_at) yang tampil. Tanpa ini, semua kunjungan BPJS bocor.
            $request->only(['tanggal', 'tanggal_from', 'tanggal_to', 'search', 'jenis', 'per_page', 'only_sent'])
        ));
    }

    /**
     * POST /klaim/rekap/sinkron-sep — tarik SEP terbit dari BPJS (Monitoring
     * Kunjungan) untuk tanggal/rentang aktif lalu tautkan ke kunjungan via
     * No.Kartu + tanggal. Untuk kunjungan yang SEP-nya dibuat di portal VClaim.
     */
    public function rekapSinkronSep(Request $request): JsonResponse
    {
        $request->validate([
            'tanggal'      => 'nullable|date',
            'tanggal_from' => 'nullable|date',
            'tanggal_to'   => 'nullable|date',
            'jenis'        => 'nullable|in:RAJAL,RANAP',
        ]);

        try {
            $data = $this->service->sinkronSepRekap(
                $request->only(['tanggal', 'tanggal_from', 'tanggal_to', 'jenis'])
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $this->statusFor($e));
        }

        $msg = $data['linked'] > 0
            ? "{$data['linked']} SEP ditautkan ke kunjungan"
            : 'Tidak ada SEP baru untuk ditautkan';

        return $this->ok($data, $msg);
    }

    /**
     * POST /klaim/rekap/{visitId}/kirim-klaim — siapkan/kirim 1 kunjungan ke daftar
     * klaim (KlaimView): salin SEP + diagnosis dari kunjungan → bpjs_claims (DRAFT).
     */
    public function rekapKirimKlaim(string $visitId): JsonResponse
    {
        try {
            $claim = $this->service->prepareClaimData($visitId);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $this->statusFor($e));
        }

        return $this->ok($claim, 'Kunjungan dikirim ke daftar klaim', 201);
    }

    /**
     * POST /klaim/rekap/kirim-klaim-massal — kirim semua kunjungan SIAP (ada SEP +
     * diagnosis utama) pada tanggal/rentang aktif ke daftar klaim sekaligus.
     */
    public function rekapKirimKlaimMassal(Request $request): JsonResponse
    {
        $request->validate([
            'tanggal'      => 'nullable|date',
            'tanggal_from' => 'nullable|date',
            'tanggal_to'   => 'nullable|date',
            'jenis'        => 'nullable|in:RAJAL,RANAP',
        ]);

        try {
            $data = $this->service->kirimKlaimMassal(
                $request->only(['tanggal', 'tanggal_from', 'tanggal_to', 'jenis'])
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $this->statusFor($e));
        }

        return $this->ok($data, "{$data['sent']} kunjungan dikirim ke klaim");
    }

    /**
     * POST /klaim/rekap/{visitId}/kembalikan — kembalikan kunjungan dari Berkas
     * Klaim (KlaimView) ke Rekap Kunjungan BPJS beserta pesan. Kunjungan hilang
     * dari tab Berkas, muncul lagi di Rekap dengan badge + pesan + Belum Lengkap.
     */
    public function rekapKembalikan(Request $request, string $visitId): JsonResponse
    {
        $request->validate(['catatan' => 'nullable|string|max:1000']);

        $user = auth('api')->user();
        try {
            $data = $this->service->returnClaimToRekap($visitId, $request->input('catatan'), $user?->id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $this->statusFor($e));
        }

        return $this->ok($data, 'Kunjungan dikembalikan ke Rekap');
    }

    /** POST /klaim/rekap/{visitId}/kelengkapan — set status kelengkapan + KET (manual). */
    public function rekapSetKelengkapan(Request $request, string $visitId): JsonResponse
    {
        $request->validate([
            'lengkap'     => 'nullable|boolean',
            'keterangan'  => 'nullable|string|max:500',
        ]);

        // null (atau tak dikirim) = belum dicek; true/false = Lengkap/Belum Lengkap.
        $raw = $request->input('lengkap');
        $lengkap = $raw === null ? null : $request->boolean('lengkap');

        try {
            $res = $this->service->setRekapKelengkapan(
                $visitId,
                $lengkap,
                $request->input('keterangan'),
                optional($request->user())->id,
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $this->statusFor($e));
        }

        return $this->ok($res, 'Status kelengkapan disimpan');
    }

    /** GET /klaim/rekap/export — unduh seluruh hasil (sesuai filter) sebagai .xlsx */
    public function rekapExport(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $filters = $request->only(['tanggal', 'tanggal_from', 'tanggal_to', 'search', 'jenis']);

        // Wajibkan rentang tanggal & batasi span → cegah ekspor SELURUH riwayat dalam
        // satu request sinkron (OOM/timeout). Maks 92 hari (≈ 1 triwulan).
        $from = $filters['tanggal_from'] ?? $filters['tanggal'] ?? null;
        $to   = $filters['tanggal_to'] ?? $filters['tanggal'] ?? null;
        if (! $from || ! $to) {
            return $this->error('Pilih rentang tanggal (dari–sampai) terlebih dahulu sebelum mengekspor.', 422);
        }
        if (\Illuminate\Support\Carbon::parse($from)->diffInDays(\Illuminate\Support\Carbon::parse($to)) > 92) {
            return $this->error('Rentang ekspor maksimal 92 hari. Persempit periode lalu coba lagi.', 422);
        }

        $filters['per_page'] = 20000; // batas aman; rentang ≤92 hari menjaga jauh di bawah ini
        $page = $this->service->getBpjsVisitRecap($filters);

        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['No', 'No SEP', 'No Kartu BPJS', 'No RM', 'Nama', 'Tgl Lahir', 'Jenis Kelamin',
            'Jenis', 'Bedah', 'Tgl SEP', 'Kelas', 'DPJP', 'Diagnosa', 'No Rujukan',
            'Kelengkapan', 'Keterangan', 'Dokumen Pendukung', 'Hasil Penunjang', 'Kwitansi'], ',', '"', '\\');

        $i = 1;
        foreach ($page as $r) {
            $kelengkapan = $r['berkas_lengkap'] === null ? 'Belum dicek' : ($r['berkas_lengkap'] ? 'Lengkap' : 'Belum Lengkap');
            fputcsv($out, [
                $i++,
                $r['no_sep'] ?? '',
                $r['bpjs_number'] ?? '',
                $r['no_rm'] ?? '',
                $r['nama'] ?? '',
                $r['tgl_lahir'] ?? '',
                $r['gender'] ?? '',
                $r['jenis'] ?? '',
                $r['is_bedah'] ? ($r['bedah_label'] ?? 'Bedah') : '',
                $r['tgl_sep'] ?? '',
                $r['kelas'] ?? '',
                $r['dpjp'] ?? '',
                $r['diagnosa'] ?? '',
                $r['no_rujukan'] ?? '',
                $kelengkapan,
                $r['keterangan'] ?? '',
                $r['dokpendukung_count'].' berkas',
                $r['penunjang_count'].' berkas',
                $r['is_paid'] ? 'LUNAS' : ($r['has_invoice'] ? 'BELUM LUNAS' : '-'),
            ], ',', '"', '\\');
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        $base = 'rekap-kunjungan-bpjs-'.now()->format('Ymd');
        $xlsx = \App\Support\SpreadsheetHelper::csvToXlsx($csv, 'Rekap BPJS');

        return response($xlsx, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$base}.xlsx\"",
        ]);
    }

    /**
     * GET /klaim/rekap/{visitId}/berkas — berkas pendukung LIVE per kunjungan:
     * dokumen RM (status TTD), hasil penunjang terstruktur, lampiran manual, + checklist.
     */
    public function rekapBerkas(string $visitId): JsonResponse
    {
        try {
            return $this->ok($this->service->getVisitBerkas($visitId));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $this->statusFor($e));
        }
    }

    /** POST /klaim/rekap/{visitId}/minta-koreksi — minta dokter koreksi diagnosa/dokumen. */
    public function rekapRequestCorrection(Request $request, string $visitId): JsonResponse
    {
        $request->validate(['catatan' => 'nullable|string|max:500']);
        try {
            $res = $this->service->requestCorrection(
                $visitId,
                $request->input('catatan'),
                optional($request->user())->id,
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $this->statusFor($e));
        }
        return $this->ok($res, 'Permintaan koreksi dikirim ke dokter');
    }

    /**
     * GET /klaim/rekap/{visitId}/lampiran — daftar lampiran kunjungan.
     * TIDAK membuat klaim (read-only): bila visit belum punya klaim → daftar kosong.
     */
    public function rekapAttachments(string $visitId): JsonResponse
    {
        try {
            $visit = \App\Models\Visit::with('bpjsClaim')->findOrFail($visitId);
            if (! $visit->bpjsClaim) {
                return $this->ok([]);
            }
            return $this->ok($this->service->getAttachments($visit->bpjsClaim->id));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $this->statusFor($e));
        }
    }

    /** POST /klaim/rekap/{visitId}/lampiran (multipart: file, category, title?) */
    public function rekapUploadAttachment(Request $request, string $visitId): JsonResponse
    {
        $request->validate([
            'file'     => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240', // 10 MB
            'category' => 'nullable|string|max:30',
            'title'    => 'nullable|string|max:200',
        ]);

        try {
            $claim = $this->service->ensureClaimForVisit($visitId);
            $att = $this->service->uploadAttachment($claim->id, $request->only(['category', 'title']), $request->file('file'));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $this->statusFor($e));
        }

        return $this->ok($att, 'Lampiran berhasil diunggah', 201);
    }

    /** DELETE /klaim/rekap/{visitId}/lampiran/{attId} */
    public function rekapDeleteAttachment(string $visitId, string $attId): JsonResponse
    {
        try {
            $claim = $this->service->ensureClaimForVisit($visitId);
            $this->service->deleteAttachment($claim->id, $attId);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $this->statusFor($e));
        }

        return $this->ok(null, 'Lampiran dihapus');
    }

    // =========================================================================
    // RESPONSE HELPERS
    // =========================================================================

    private function ok(mixed $data, string $message = 'Berhasil', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $data,
            'message' => $message,
            'errors'  => null,
        ], $status);
    }

    private function error(string $message, int|string $status = 500): JsonResponse
    {
        // Coerce non-int status (e.g. PDO SQLSTATE string from QueryException) to a valid HTTP code.
        $status = (is_int($status) && $status >= 400 && $status < 600) ? $status : 500;
        // Jangan bocorkan detail teknis SQL/koneksi ke klien — pesan QueryException
        // memuat query + parameter (NIK/No.SEP) & skema DB. Sensor → 500 generik, detail ke log.
        if (str_contains($message, 'SQLSTATE') || str_contains($message, ' SQL: ')) {
            Log::error('Klaim error (disensor dari klien): ' . $message);
            $message = 'Terjadi kesalahan pada server. Silakan coba lagi atau hubungi admin.';
            $status = 500;
        }
        return response()->json([
            'success' => false,
            'data'    => null,
            'message' => $message,
            'errors'  => null,
        ], $status);
    }

    /**
     * Status HTTP dari exception. ModelNotFound (findOrFail) → 404, bukan 422.
     * getCode() bisa 0 (mis. ModelNotFound) atau non-int (SQLSTATE) → fallback aman.
     */
    private function statusFor(\Throwable $e, int $default = 422): int
    {
        if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
            return 404;
        }
        // QueryException/PDO (mis. {id} non-UUID, konflik unik) = galat server, bukan 422.
        if ($e instanceof \Illuminate\Database\QueryException || $e instanceof \PDOException) {
            return 500;
        }
        $code = $e->getCode();

        return (is_int($code) && $code >= 400 && $code < 600) ? $code : $default;
    }
}
