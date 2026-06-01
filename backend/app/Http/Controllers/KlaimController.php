<?php

namespace App\Http\Controllers;

use App\Models\Icd10Code;
use App\Models\Icd9Code;
use App\Services\KlaimService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($claim, $request->input('assigned_to_id') ? 'Klaim ditandai dikerjakan' : 'Penanda dilepas');
    }

    /** GET /klaim/{id} — diperkaya label ICD + total billing untuk KlaimView. */
    public function show(string $id): JsonResponse
    {
        $claim = $this->service->getClaimById($id);

        // Lookup label ICD (DB klaim hanya simpan kode). Cache per-request.
        $icd10 = Icd10Code::pluck('description', 'code');
        $icd10Id = Icd10Code::pluck('indonesian_description', 'code');
        $icd9  = Icd9Code::pluck('description', 'code');
        $icd9Id = Icd9Code::pluck('indonesian_description', 'code');

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
        $data['assigned_to'] = $claim->assignedTo ? [
            'id'   => $claim->assignedTo->id,
            'name' => $claim->assignedTo->name,
        ] : null;

        // Dokumen pendukung = PatientDocument pada visit klaim (FINAL diutamakan).
        $data['dokumen_pendukung'] = \App\Models\PatientDocument::with('documentType')
            ->where('visit_id', $claim->visit_id)
            ->orderByDesc('finalized_at')
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
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
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
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
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
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($claim, 'Klaim dalam proses review');
    }

    /** PUT /klaim/{id}/verifikasi → REVIEW → VERIFIED */
    public function setVerifikasi(string $id): JsonResponse
    {
        try {
            $claim = $this->service->setVerifikasi($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
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
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($claim, 'Klaim dikembalikan untuk perbaikan');
    }

    /** POST /klaim/{id}/resubmit — ajukan ulang klaim yang dikembalikan/ditolak (→ DRAFT) */
    public function resubmitKlaim(string $id): JsonResponse
    {
        try {
            $claim = $this->service->resubmitClaim($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
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
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
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
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($claim, 'Data LUPIS berhasil di-generate');
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
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
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
            return $this->error($e->getMessage(), $e->getCode() ?: 404);
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
            return $this->error($e->getMessage(), $e->getCode() ?: 503);
        }

        return $this->ok($data);
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
        return response()->json([
            'success' => false,
            'data'    => null,
            'message' => $message,
            'errors'  => null,
        ], $status);
    }
}
