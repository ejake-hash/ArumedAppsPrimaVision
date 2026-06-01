<?php

namespace App\Http\Controllers;

use App\Services\AsuransiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Modul Asuransi/TPA Non-BPJS — controller tipis, semua logika di AsuransiService.
 * Tidak menyentuh KlaimController/BPJS flow.
 */
class AsuransiController extends Controller
{
    public function __construct(private readonly AsuransiService $service) {}

    // =========================================================================
    // VERIFIKASI
    // =========================================================================

    public function showVerifikasi(string $visitId): JsonResponse
    {
        return $this->ok($this->service->getVerifikasi($visitId));
    }

    public function pendingVerifications(Request $request): JsonResponse
    {
        $date = $request->query('date');
        return $this->ok($this->service->pendingVerifications($date));
    }

    /**
     * Kunjungan asuransi yang sudah diverifikasi & sedang dilayani (belum lunas).
     */
    public function inServiceVerifications(Request $request): JsonResponse
    {
        $date = $request->query('date');
        return $this->ok($this->service->inServiceVerifications($date));
    }

    /**
     * Rincian tagihan pasien (read-only) untuk admin asuransi saat menentukan cover.
     */
    public function showBilling(string $visitId): JsonResponse
    {
        return $this->ok($this->service->getBilling($visitId));
    }

    public function storeVerifikasi(Request $request): JsonResponse
    {
        $data = $request->validate([
            'visit_id'           => 'required|uuid|exists:visits,id',
            'insurer_id'         => 'required|uuid|exists:insurers,id',
            'status'             => 'nullable|in:PENDING,VERIFIED,NEEDS_CLARIFICATION,REJECTED',
            'policy_number'      => 'nullable|string|max:255',
            'member_name'        => 'nullable|string|max:255',
            'member_card_number' => 'nullable|string|max:255',
            'plafon_amount'      => 'nullable|numeric|min:0',
            'copayment_percent'  => 'nullable|numeric|min:0|max:100',
            'copayment_amount'   => 'nullable|numeric|min:0',
            'covered_amount'     => 'nullable|numeric|min:0',
            'coverage_notes'     => 'nullable|string',
            'exclusion_flags'    => 'nullable|array',
            'issue_notes'        => 'nullable|string',
        ]);

        return $this->ok($this->service->createVerifikasi($data), 'Verifikasi disimpan', 201);
    }

    public function updateVerifikasi(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'status'             => 'nullable|in:PENDING,VERIFIED,NEEDS_CLARIFICATION,REJECTED',
            'policy_number'      => 'nullable|string|max:255',
            'member_name'        => 'nullable|string|max:255',
            'member_card_number' => 'nullable|string|max:255',
            'plafon_amount'      => 'nullable|numeric|min:0',
            'copayment_percent'  => 'nullable|numeric|min:0|max:100',
            'copayment_amount'   => 'nullable|numeric|min:0',
            'covered_amount'     => 'nullable|numeric|min:0',
            'coverage_notes'     => 'nullable|string',
            'exclusion_flags'    => 'nullable|array',
            'issue_notes'        => 'nullable|string',
        ]);

        return $this->ok($this->service->updateVerifikasi($id, $data), 'Verifikasi diperbarui');
    }

    // =========================================================================
    // KLAIM
    // =========================================================================

    public function indexKlaim(Request $request): JsonResponse
    {
        $filters = $request->only(['status', 'insurer_id', 'date_from', 'date_to', 'search', 'per_page']);
        return $this->ok($this->service->indexKlaim($filters));
    }

    public function showKlaim(string $id): JsonResponse
    {
        return $this->ok($this->service->showKlaim($id));
    }

    public function storeKlaim(Request $request): JsonResponse
    {
        $data = $request->validate([
            'visit_id'                  => 'required|uuid|exists:visits,id',
            'insurer_id'                => 'required|uuid|exists:insurers,id',
            'billing_invoice_id'        => 'nullable|uuid|exists:billing_invoices,id',
            'insurance_verification_id' => 'nullable|uuid|exists:insurance_verifications,id',
            'claim_amount'              => 'nullable|numeric|min:0',
            'patient_responsibility'    => 'nullable|numeric|min:0',
            'notes'                     => 'nullable|string',
            'source'                    => 'nullable|string|max:50',
        ]);

        return $this->ok($this->service->createDraftKlaim($data), 'Draft klaim dibuat', 201);
    }

    public function updateKlaim(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'claim_amount'           => 'nullable|numeric|min:0',
            'patient_responsibility' => 'nullable|numeric|min:0',
            'documents_checklist'    => 'nullable|array',
            'notes'                  => 'nullable|string',
            'billing_invoice_id'     => 'nullable|uuid|exists:billing_invoices,id',
        ]);

        return $this->ok($this->service->updateKlaim($id, $data), 'Draft klaim diperbarui');
    }

    public function submitKlaim(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'submission_ref'      => 'required|string|max:255',
            'documents_checklist' => 'nullable|array',
            'claim_amount'        => 'nullable|numeric|min:0',
            'notes'               => 'nullable|string',
        ]);

        return $this->ok($this->service->submitKlaim($id, $data), 'Klaim disubmit');
    }

    public function updateStatusKlaim(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'status'           => 'required|in:APPROVED,REJECTED,APPEALED',
            'approved_amount'  => 'nullable|numeric|min:0|required_if:status,APPROVED',
            'rejection_code'   => 'nullable|string|max:50',
            'rejection_reason' => 'nullable|string',
            'appeal_notes'     => 'nullable|string',
        ]);

        return $this->ok($this->service->updateStatusKlaim($id, $data), "Status klaim diubah ke {$data['status']}");
    }

    public function resubmitKlaim(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'submission_ref'      => 'required|string|max:255',
            'documents_checklist' => 'nullable|array',
            'notes'               => 'nullable|string',
        ]);

        return $this->ok($this->service->resubmitKlaim($id, $data), 'Klaim disubmit ulang');
    }

    public function logsKlaim(string $id): JsonResponse
    {
        return $this->ok($this->service->getLogs($id));
    }

    // =========================================================================
    // LAPORAN
    // =========================================================================

    public function agingReport(): JsonResponse
    {
        return $this->ok($this->service->getAgingReport());
    }

    public function outstandingReport(): JsonResponse
    {
        // Sama dengan aging tapi flag overdue terpisah
        $aging = $this->service->getAgingReport();
        return $this->ok([
            'total'     => count($aging),
            'overdue'   => collect($aging)->where('is_overdue', true)->count(),
            'items'     => $aging,
        ]);
    }

    public function dashboardSummary(): JsonResponse
    {
        return $this->ok($this->service->dashboardSummary());
    }

    // =========================================================================
    // MASTER — Document Requirements per TPA
    // =========================================================================

    public function indexDocRequirement(string $insurerId): JsonResponse
    {
        return $this->ok($this->service->indexDocRequirement($insurerId));
    }

    public function storeDocRequirement(Request $request, string $insurerId): JsonResponse
    {
        $data = $request->validate([
            'document_name' => 'required|string|max:255',
            'is_required'   => 'nullable|boolean',
            'notes'         => 'nullable|string',
            'sort_order'    => 'nullable|integer|min:0',
        ]);
        return $this->ok($this->service->storeDocRequirement($insurerId, $data), 'Dokumen requirement ditambahkan', 201);
    }

    public function updateDocRequirement(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'document_name' => 'nullable|string|max:255',
            'is_required'   => 'nullable|boolean',
            'notes'         => 'nullable|string',
            'sort_order'    => 'nullable|integer|min:0',
        ]);
        return $this->ok($this->service->updateDocRequirement($id, $data), 'Dokumen requirement diperbarui');
    }

    public function deleteDocRequirement(string $id): JsonResponse
    {
        $this->service->deleteDocRequirement($id);
        return $this->ok(null, 'Dokumen requirement dihapus');
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
